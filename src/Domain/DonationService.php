<?php

namespace MatchBot\Domain;

use Doctrine\DBAL\Exception\ServerException as DBALServerException;
use Doctrine\ORM\Exception\ORMException;
use MatchBot\Application\Matching\Adapter as MatchingAdapter;
use MatchBot\Application\Notifier\StripeChatterInterface;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
use MatchBot\Client\Stripe;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\DomainException\CampaignNotOpen;
use MatchBot\Domain\DomainException\CharityAccountLacksNeededCapaiblities;
use MatchBot\Domain\DomainException\CouldNotMakeStripePaymentIntent;
use MatchBot\Domain\DomainException\DonationCreateModelLoadFailure;
use MatchBot\Domain\DomainException\StripeAccountIdNotSetForAccount;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Random\Randomizer;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\RateLimiter\Exception\RateLimitExceededException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

readonly class DonationService
{
    private const MAX_RETRY_COUNT = 3;

    public function __construct(
        private DonationRepository $donationRepository,
        private CampaignRepository $campaignRepository,
        private LoggerInterface $logger,
        private RetrySafeEntityManager $entityManager,
        private Stripe $stripe,
        private MatchingAdapter $matchingAdapter,
        private StripeChatterInterface|ChatterInterface $chatter,
        private ClockInterface $clock,
        private RateLimiterFactory $rateLimiterFactory,
    ) {
    }

    /**
     * Creates a new pending donation. In some edge cases (initial campaign data inserts hitting
     * unique constraint violations), may reset the EntityManager; this could cause previously
     * tracked entities in the Unit of Work to be lost.
     *
     * @param DonationCreate $donationData Details of the desired donation, as sent from the browser
     * @param string $pspCustomerId The Stripe customer ID of the donor
     *
     * @throws CampaignNotOpen
     * @throws CharityAccountLacksNeededCapaiblities
     * @throws CouldNotMakeStripePaymentIntent
     * @throws DBALServerException
     * @throws DonationCreateModelLoadFailure
     * @throws ORMException
     * @throws StripeAccountIdNotSetForAccount
     * @throws TransportExceptionInterface
     * @throws RateLimitExceededException
     */
    public function createDonation(DonationCreate $donationData, string $pspCustomerId): Donation
    {
        $this->rateLimiterFactory->create(key: $pspCustomerId)->consume()->ensureAccepted();

        try {
            $donation = $this->donationRepository->buildFromApiRequest($donationData);
        } catch (\UnexpectedValueException $e) {
            $message = 'Donation Create data initial model load';
            $this->logger->warning($message . ': ' . $e->getMessage());

            throw new DonationCreateModelLoadFailure(previous: $e);
        } catch (UniqueConstraintViolationException) {
            // If we get this, the most likely explanation is that another donation request
            // created the same campaign a very short time before this request tried to. We
            // saw this 3 times in the opening minutes of CC20 on 1 Dec 2020.
            // If this happens, the latest campaign data should already have been pulled and
            // persisted in the last second. So give the same call one more try, as
            // buildFromApiRequest() should perform a fresh call to `CampaignRepository::findOneBy()`.
            $this->logger->info(sprintf(
                'Got campaign pull UniqueConstraintViolationException for campaign ID %s. Trying once more.',
                $donationData->projectId->value,
            ));
            $donation = $this->donationRepository->buildFromApiRequest($donationData);
        }

        if ($pspCustomerId !== $donation->getPspCustomerId()) {
            throw new \UnexpectedValueException(sprintf(
                'Route customer ID %s did not match %s in donation body',
                $pspCustomerId,
                $donation->getPspCustomerId()
            ));
        }

        if (!$donation->getCampaign()->isOpen()) {
            throw new CampaignNotOpen("Campaign {$donation->getCampaign()->getSalesforceId()} is not open");
        }

        // A closed EM can happen if the above tried to insert a campaign or fund, hit a duplicate error because
        // another thread did it already, then successfully got the new copy. There's been no subsequent
        // database persistence that needed an open manager, so none replaced the broken one. In that
        // edge case, we need to handle that before `persistWithoutRetries()` has a chance of working.
        if (!$this->entityManager->isOpen()) {
            $this->entityManager->resetManager();
        }

        // Must persist before Stripe work to have ID available. Outer fn throws if all attempts fail.
        $this->runWithPossibleRetry(function () use ($donation) {
            $this->entityManager->persistWithoutRetries($donation);
            $this->entityManager->flush();
        }, 'Donation Create persist before stripe work');

        if ($donation->getCampaign()->isMatched()) {
            $this->runWithPossibleRetry(
                function () use ($donation) {
                    try {
                        $this->donationRepository->allocateMatchFunds($donation);
                    } catch (\Throwable $t) {
                        // warning indicates that we *may* retry, as it depends on whether this is in the last retry or
                        // not.
                        $this->logger->warning(sprintf('Allocation got error, may retry: %s', $t->getMessage()));

                        $this->matchingAdapter->releaseNewlyAllocatedFunds();

                        // we have to also remove the FundingWithdrawls from MySQL - otherwise the redis amount
                        // would be reduced again when the donation expires.
                        $this->donationRepository->removeAllFundingWithdrawalsForDonation($donation);

                        throw $t;
                    }
                },
                'allocate match funds'
            );
        }

        if ($donation->getPsp() === 'stripe') {
            if (empty($donation->getCampaign()->getCharity()->getStripeAccountId())) {
                // Try re-pulling in case charity has very recently onboarded with for Stripe.
                $campaign = $donation->getCampaign();
                $this->campaignRepository->updateFromSf($campaign);

                // If still empty, error out
                if (empty($campaign->getCharity()->getStripeAccountId())) {
                    $this->logger->error(sprintf(
                        'Stripe Payment Intent create error: Stripe Account ID not set for Account %s',
                        $donation->getCampaign()->getCharity()->getSalesforceId(),
                    ));
                    throw new StripeAccountIdNotSetForAccount(
                    );
                }

                // Else we found new Stripe info and can proceed
                $donation->setCampaign($campaign);
            }

            $createPayload = [
                ...$donation->getStripeMethodProperties(),
                ...$donation->getStripeOnBehalfOfProperties(),
                'customer' => $pspCustomerId,
                // Stripe Payment Intent `amount` is in the smallest currency unit, e.g. pence.
                // See https://stripe.com/docs/api/payment_intents/object
                'amount' => $donation->getAmountFractionalIncTip(),
                'currency' => strtolower($donation->getCurrencyCode()),
                'description' => $donation->getDescription(),
                'capture_method' => 'automatic', // 'automatic' was default in previous API versions,
                                                // default is now 'automatic_async'
                'metadata' => [
                    /**
                     * Keys like comms opt ins are set only later. See the counterpart
                     * in {@see Update::addData()} too.
                     */
                    'campaignId' => $donation->getCampaign()->getSalesforceId(),
                    'campaignName' => $donation->getCampaign()->getCampaignName(),
                    'charityId' => $donation->getCampaign()->getCharity()->getSalesforceId(),
                    'charityName' => $donation->getCampaign()->getCharity()->getName(),
                    'donationId' => $donation->getUuid(),
                    'environment' => getenv('APP_ENV'),
                    'feeCoverAmount' => $donation->getFeeCoverAmount(),
                    'matchedAmount' => $donation->getFundingWithdrawalTotal(),
                    'stripeFeeRechargeGross' => $donation->getCharityFeeGross(),
                    'stripeFeeRechargeNet' => $donation->getCharityFee(),
                    'stripeFeeRechargeVat' => $donation->getCharityFeeVat(),
                    'tipAmount' => $donation->getTipAmount(),
                ],
                'statement_descriptor' => $this->getStatementDescriptor($donation->getCampaign()->getCharity()),
                // See https://stripe.com/docs/connect/destination-charges#application-fee
                'application_fee_amount' => $donation->getAmountToDeductFractional(),
                'transfer_data' => [
                    'destination' => $donation->getCampaign()->getCharity()->getStripeAccountId(),
                ],
            ];

            if ($donation->supportsSavingPaymentMethod()) {
                $createPayload['setup_future_usage'] = 'on_session';
            }

            try {
                $intent = $this->stripe->createPaymentIntent($createPayload);
            } catch (ApiErrorException $exception) {
                $message = $exception->getMessage();

                $accountLacksCapabilities = str_contains(
                    $message,
                    // this message is an issue the charity needs to fix, we can't fix it for them.
                    // We likely want to let the team know to hide the campaign from prominents views though.
                    'Your destination account needs to have at least one of the following capabilities enabled'
                );

                $failureMessage = sprintf(
                    'Stripe Payment Intent create error on %s, %s [%s]: %s. Charity: %s [%s].',
                    $donation->getUuid(),
                    $exception->getStripeCode() ?? 'unknown',
                    get_class($exception),
                    $message,
                    $donation->getCampaign()->getCharity()->getName(),
                    $donation->getCampaign()->getCharity()->getStripeAccountId() ?? 'unknown',
                );

                $level = $accountLacksCapabilities ? LogLevel::WARNING : LogLevel::ERROR;
                $this->logger->log($level, $failureMessage);

                if ($accountLacksCapabilities) {
                    $env = getenv('APP_ENV');
                    \assert(is_string($env));
                    $failureMessageWithContext = sprintf(
                        '[%s] %s',
                        $env,
                        $failureMessage,
                    );
                    $this->chatter->send(new ChatMessage($failureMessageWithContext));

                    throw new CharityAccountLacksNeededCapaiblities();
                }

                throw new CouldNotMakeStripePaymentIntent();
            }

            $donation->setTransactionId($intent->id);

            $this->runWithPossibleRetry(
                function () use ($donation) {
                    $this->entityManager->persistWithoutRetries($donation);
                    $this->entityManager->flush();
                },
                'Donation Create persist after stripe work'
            );
        }

        return $donation;
    }

    private function getStatementDescriptor(Charity $charity): string
    {
        $maximumLength = 22; // https://stripe.com/docs/payments/payment-intents#dynamic-statement-descriptor
        $prefix = 'Big Give ';

        return $prefix . mb_substr(
            $this->removeSpecialChars($charity->getName()),
            0,
            $maximumLength - mb_strlen($prefix),
        );
    }

    // Remove special characters except spaces
    private function removeSpecialChars(string $descriptor): string
    {
        return preg_replace('/[^A-Za-z0-9 ]/', '', $descriptor);
    }

    /**
     * It seems like just the *first* persist of a given donation needs to be retry-safe, since there is a small
     * but non-zero minority of Create attempts at the start of a big campaign which get a closed Entity Manager
     * and then don't know about the connected #campaign on persist and crash when RetrySafeEntityManager tries again.
     *
     * The same applies to allocating match funds, which in rare cases can fail with a lock timeout exception. It could
     * also fail simply because another thread keeps changing the values of funds in redis.
     *
     * If the EM "goes away" for any reason but only does so once, `flush()` should still replace the underlying
     * EM with a new one and then the next persist should succeed.
     *
     * If the persist itself fails, we do not replace the underlying entity manager. This means if it's still usable
     * then we still have any required related new entities in the Unit of Work.
     * @param \Closure $retryable The action to be executed and then retried if necassary
     * @param string $actionName The name of the action, used in logs.
     * @throws ORMException|DBALServerException if they're occurring when max retry count reached.
     */
    private function runWithPossibleRetry(\Closure $retryable, string $actionName): void
    {
        $retryCount = 0;
        while ($retryCount < self::MAX_RETRY_COUNT) {
            try {
                $retryable();
                return;
            } catch (ORMException | DBALServerException $exception) {
                $retryCount++;
                $this->logger->info(
                    sprintf(
                        $actionName . ' error: %s. Retrying %d of %d.',
                        $exception->getMessage(),
                        $retryCount,
                        self::MAX_RETRY_COUNT,
                    )
                );

                $seconds = (new Randomizer())->getFloat(0.1, 1.1);
                \assert(is_float($seconds)); // See https://github.com/vimeo/psalm/issues/10830
                $this->clock->sleep($seconds);

                if ($retryCount === self::MAX_RETRY_COUNT) {
                    $this->logger->error(
                        sprintf(
                            $actionName . ' error: %s. Giving up after %d retries.',
                            $exception->getMessage(),
                            self::MAX_RETRY_COUNT,
                        )
                    );

                    throw $exception;
                }
            }
        }
    }
}
