<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use Doctrine\DBAL\Exception\ServerException as DBALServerException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\Exception\ORMException;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Actions\ActionError;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\AssertionFailedException;
use MatchBot\Application\Auth\DonationToken;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\HttpModels\DonationCreatedResponse;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Application\Notifier\StripeChatterInterface;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
use MatchBot\Client\Stripe;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Charity;
use MatchBot\Domain\DonationRepository;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Random\Randomizer;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\SerializerInterface;

class Create extends Action
{
    private const MAX_RETRY_COUNT = 4;
    private ChatterInterface $chatter;

    public function __construct(
        private DonationRepository $donationRepository,
        private CampaignRepository $campaignRepository,
        private RetrySafeEntityManager $entityManager,
        private SerializerInterface $serializer,
        private Stripe $stripe,
        private Adapter $matchingAdapter,
        private ClockInterface $clock,
        ContainerInterface $container,
        LoggerInterface $logger,
    ) {
        /**
         * @var ChatterInterface $chatter
         * Injecting `StripeChatterInterface` directly doesn't work because `Chatter` itself
         * is final and does not implement our custom interface.
         */
        $chatter = $container->get(StripeChatterInterface::class);
        $this->chatter = $chatter;

        parent::__construct($logger);
    }

    /**
     * @return Response
     * @throws \MatchBot\Application\Matching\TerminalLockException if the matching adapter can't allocate funds
     * @see PersonManagementAuthMiddleware
     */
    protected function action(Request $request, Response $response, array $args): Response
    {
        // The route at `/people/{personId}/donations` validates that the donor has permission to act
        // as the person, and sets this attribute to the Stripe Customer ID based on JWS claims, all
        // in `PersonManagementAuthMiddleware`. If the legacy route was used or if no such ID was in the
        // JWS, this is null.
        $customerId = $request->getAttribute(PersonWithPasswordAuthMiddleware::PSP_ATTRIBUTE_NAME);

        $body = (string) $request->getBody();

        try {
            /** @var DonationCreate $donationData */
            $donationData = $this->serializer->deserialize($body, DonationCreate::class, 'json');
        } catch (\TypeError | UnexpectedValueException | AssertionFailedException $exception) {
            // UnexpectedValueException is the Serializer one,
            // not the global one

            // Ideally rather than catching type error we would configure the seralizer use
            // the Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor and then it would throw a
            // NotNormalizableValueException instead of calling the constructor and having that throw a TypeError.
            //
            // But that requires more changes than I want to make right now to either Donation http model used for
            // updates, as well as the DonationCreate model used here or the tests that sometimes pass strings where
            // numbers are specified or vice versa.

            $this->logger->info("Donation Create non-serialisable payload was: $body");

            $message = 'Donation Create data deserialise error';
            $exceptionType = get_class($exception);

            return $this->validationError(
                $response,
                "$message: $exceptionType - {$exception->getMessage()}",
                $message,
                empty($body), // Suspected bot / junk traffic sometimes sends blank payload.
            );
        }

        try {
            $donation = $this->donationRepository->buildFromApiRequest($donationData);
        } catch (\UnexpectedValueException $exception) {
            $this->logger->info("Donation Create model load failure payload was: $body");

            $message = 'Donation Create data initial model load';
            $this->logger->warning($message . ': ' . $exception->getMessage());

            return $this->validationError(
                $response,
                $message . ': ' . $exception->getMessage(),
                $exception->getMessage(),
            );
        } catch (UniqueConstraintViolationException $exception) {
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

        if ($customerId !== $donation->getPspCustomerId()) {
            return $this->validationError($response, sprintf(
                'Route customer ID %s did not match %s in donation body',
                $customerId,
                $donation->getPspCustomerId(),
            ));
        }

        if (!$donation->getCampaign()->isOpen()) {
            return $this->validationError(
                $response,
                "Campaign {$donation->getCampaign()->getSalesforceId()} is not open",
                null,
                true, // Reduce to info log as some instances expected on campaign close
            );
        }

        // Must persist before Stripe work to have ID available.
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
                    $error = new ActionError(ActionError::SERVER_ERROR, 'Could not make Stripe Payment Intent (A)');
                    return $this->respond($response, new ActionPayload(500, null, $error));
                }

                // Else we found new Stripe info and can proceed
                $donation->setCampaign($campaign);
            }

            $createPayload = [
                ...$donation->getStripeMethodProperties(),
                ...$donation->getStripeOnBehalfOfProperties(),
                // Stripe Payment Intent `amount` is in the smallest currency unit, e.g. pence.
                // See https://stripe.com/docs/api/payment_intents/object
                'amount' => $donation->getAmountFractionalIncTip(),
                'currency' => strtolower($donation->getCurrencyCode()),
                'description' => $donation->getDescription(),
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

            // For now 'customer' may be omitted – and an automatic, guest customer used by Stripe –
            // depending on the frontend mode. If there *is* a customer, we want to be able to offer them
            // card reuse.
            if ($customerId !== null) {
                $createPayload['customer'] = $customerId;

                if ($donation->supportsSavingPaymentMethod()) {
                    $createPayload['setup_future_usage'] = 'on_session';
                }
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
                }

                $error = new ActionError(
                    $accountLacksCapabilities ? ActionError::VERIFICATION_ERROR : ActionError::SERVER_ERROR,
                    'Could not make Stripe Payment Intent (B)'
                );

                return $this->respond(
                    $response,
                    new ActionPayload(
                        // HTTP 409 Conflict can cover when requests conflicts w/ server configuration.
                        $accountLacksCapabilities ? 409 : 500,
                        null,
                        $error,
                    ),
                );
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

        $data = new DonationCreatedResponse();
        $data->donation = $donation->toApiModel();
        $data->jwt = DonationToken::create($donation->getUuid());

        // Attempt immediate sync. Buffered for a future batch sync if the SF call fails.
        $this->donationRepository->push($donation, true);

        return $this->respondWithData($response, $data, 201);
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
            }
        }
    }
}
