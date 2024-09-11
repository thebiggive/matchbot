<?php

namespace MatchBot\Domain;

use Doctrine\DBAL\Exception\ServerException as DBALServerException;
use Doctrine\ORM\Exception\ORMException;
use MatchBot\Application\Assertion;
use MatchBot\Application\Fees\Calculator;
use MatchBot\Application\Matching\Adapter as MatchingAdapter;
use MatchBot\Application\Notifier\StripeChatterInterface;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
use MatchBot\Client\CampaignNotReady;
use MatchBot\Client\Stripe;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\DomainException\CampaignNotOpen;
use MatchBot\Domain\DomainException\CharityAccountLacksNeededCapaiblities;
use MatchBot\Domain\DomainException\CouldNotMakeStripePaymentIntent;
use MatchBot\Domain\DomainException\DonationCreateModelLoadFailure;
use MatchBot\Domain\DomainException\MandateNotActive;
use MatchBot\Domain\DomainException\NoDonorAccountException;
use MatchBot\Domain\DomainException\StripeAccountIdNotSetForAccount;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Random\Randomizer;
use Slim\Exception\HttpBadRequestException;
use Stripe\Card;
use Stripe\Exception\ApiErrorException;
use Stripe\Mandate;
use Stripe\StripeObject;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\RateLimiter\Exception\RateLimitExceededException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class DonationService
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
        private DonorAccountRepository $donorAccountRepository,
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
     * @throws CampaignNotReady|\MatchBot\Client\NotFoundException
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

        if ($pspCustomerId !== $donation->getPspCustomerId()?->stripeCustomerId) {
            throw new \UnexpectedValueException(sprintf(
                'Route customer ID %s did not match %s in donation body',
                $pspCustomerId,
                $donation->getPspCustomerId()?->stripeCustomerId ?? 'null'
            ));
        }

        $this->enrollNewDonation($donation);

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

    /**
     * Finalized a donation, instructing stripe to attempt to take payment immediately for a donor
     * making an immediate, online donation.
     */
    public function confirmOnSessionDonation(
        Donation $donation,
        StripePaymentMethodId|StripeConformationTokenId $tokenId
    ): \Stripe\PaymentIntent {
        if ($tokenId instanceof StripePaymentMethodId) {
            $this->updateDonationFees($tokenId, $donation);
        } else {
            $this->updateDonationFeesFromConfirmationToken($tokenId, $donation);
        }
        return $this->confirm($donation, $tokenId);
    }

    /**
     * Finalized a donation, instructing stripe to attempt to take payment.
     */
    private function confirm(
        Donation $donation,
        StripePaymentMethodId|StripeConformationTokenId $tokenId
    ): \Stripe\PaymentIntent {
        $params = [
            ...($tokenId instanceof StripePaymentMethodId ?
                ['payment_method' => $tokenId->stripePaymentMethodId] : []),

            ...($tokenId instanceof StripeConformationTokenId ?
                ['confirmation_token' => $tokenId->stripeConfirmationTokenId] : []),
        ];

        $paymentIntentId = $donation->getTransactionId();

        return $this->stripe->confirmPaymentIntent($paymentIntentId, $params);
    }

    private function updateDonationFees(StripePaymentMethodId $paymentMethodId, Donation $donation): void
    {
        $paymentMethod = $this->stripe->retrievePaymentMethod($paymentMethodId);

        if ($paymentMethod->type !== 'card') {
            throw new \DomainException('Confirm only supports card payments for now');
        }

        /**
         * This is not technically true - at runtime this is a StripeObject instance, but the behaviour seems to be
         * as documented in the Card class. Stripe SDK is interesting. Without this annotation we would have SA
         * errors on ->brand and ->country
         * @var Card $card
         */
        $card = $paymentMethod->card;

        // documented at https://stripe.com/docs/api/payment_methods/object?lang=php
        // Contrary to what Stripes docblock says, in my testing 'brand' is strings like 'visa' or 'amex'. Not
        // 'Visa' or 'American Express'
        $cardBrand = $card->brand;

        // two letter upper string, e.g. 'GB', 'US'.
        $cardCountry = $card->country;
        \assert(is_string($cardCountry));

        $this->doUpdateDonationFees($cardBrand, $donation, $cardCountry, $donation->supportsSavingPaymentMethod());
    }

    /**
     * Trigger collection of funds from a pre-authorized donation associated with a regular giving mandate
     */
    public function confirmPreAuthorized(Donation $donation): void
    {
        $stripeAccountId = $donation->getPspCustomerId();
        Assertion::notNull($stripeAccountId);
        $donorAccount = $this->donorAccountRepository->findByStripeIdOrNull($stripeAccountId);

        if ($donorAccount === null) {
            throw new NoDonorAccountException("Donor account not found for donation $donation");
        }

        $mandate = $donation->getMandate();
        \assert($mandate !== null);
        $currentMandateStatus = $mandate->getStatus();
        if ($currentMandateStatus !== MandateStatus::Active) {
            throw new MandateNotActive(
                "Not confirming donation as mandate is '{$currentMandateStatus->name}', not Active"
            );
        }

        $paymentMethod = $donorAccount->getRegularGivingPaymentMethod();

        if ($paymentMethod === null) {
            throw new \MatchBot\Domain\NoRegularGivingPaymentMethod(
                "Cannot confirm donation {$donation->getUuid()} for " .
                "{$donorAccount->stripeCustomerId->stripeCustomerId}, no payment method"
            );
        }

        $this->confirm($donation, $paymentMethod);
    }

    /**
     * Does multiple things required when a new donation is added to the system including:
     * - Checking that the campaign is open
     * - Allocating match funds to the donation
     * - Creating Stripe Payment intent
     *
     * @throws CampaignNotOpen
     * @throws CampaignNotReady
     * @throws CharityAccountLacksNeededCapaiblities
     * @throws CouldNotMakeStripePaymentIntent
     * @throws DBALServerException
     * @throws ORMException
     * @throws StripeAccountIdNotSetForAccount
     * @throws TransportExceptionInterface
     * @throws \MatchBot\Client\NotFoundException
     */
    public function enrollNewDonation(Donation $donation): void
    {
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
            $stripeAccountId = $donation->getCampaign()->getCharity()->getStripeAccountId();
            if ($stripeAccountId === null || $stripeAccountId === '') {
                // Try re-pulling in case charity has very recently onboarded with for Stripe.
                $campaign = $donation->getCampaign();
                $this->campaignRepository->updateFromSf($campaign);

                // If still empty, error out
                $stripeAccountId = $campaign->getCharity()->getStripeAccountId();
                if ($stripeAccountId === null || $stripeAccountId === '') {
                    $this->logger->error(sprintf(
                        'Stripe Payment Intent create error: Stripe Account ID not set for Account %s',
                        $donation->getCampaign()->getCharity()->getSalesforceId() ?? 'missing charity sf ID',
                    ));
                    throw new StripeAccountIdNotSetForAccount();
                }

                // Else we found new Stripe info and can proceed
                $donation->setCampaign($campaign);
            }

            $this->createPaymentIntent($donation);
        }
    }

    public function doUpdateDonationFees(
        string $cardBrand,
        Donation $donation,
        string $cardCountry,
        bool $savePaymentMethod
    ): void {
        Assertion::inArray($cardBrand, Calculator::STRIPE_CARD_BRANDS);

// at present if the following line was left out we would charge a wrong fee in some cases. I'm not happy with
        // that, would like to find a way to make it so if its left out we get an error instead - either by having
        // derive fees return a value, or making functions like Donation::getCharityFeeGross throw if called before it.
        $donation->deriveFees($cardBrand, $cardCountry);

        // we still need this
        $updatedIntentData = [
            // only setting things that may need to be updated at this point.
            'metadata' => [
                'stripeFeeRechargeGross' => $donation->getCharityFeeGross(),
                'stripeFeeRechargeNet' => $donation->getCharityFee(),
                'stripeFeeRechargeVat' => $donation->getCharityFeeVat(),
            ],

            // See https://stripe.com/docs/connect/destination-charges#application-fee
            // Update the fee amount in case the final charge was from
            // e.g. a Non EU / Amex card where fees are varied.
            'application_fee_amount' => $donation->getAmountToDeductFractional(),
            // Note that `on_behalf_of` is set up on create and is *not allowed* on update.
        ];

        if ($savePaymentMethod) {
            $updatedIntentData['setup_future_usage'] = 'on_session';
        }

        $this->stripe->updatePaymentIntent($donation->getTransactionId(), $updatedIntentData);
    }

    private function updateDonationFeesFromConfirmationToken(
        StripeConformationTokenId $tokenId,
        Donation $donation
    ): void {
        $token = $this->stripe->retrieveConfirmationToken($tokenId);

        /** @var StripeObject $paymentMethodPreview */
        $paymentMethodPreview = $token->payment_method_preview;

        /** @var StripeObject $card */
        $card = $paymentMethodPreview['card'];

        $cardBrand = $card['brand'];
        $cardCountry = $card['country'];

        Assertion::string($cardBrand);
        Assertion::string($cardCountry);

        // whether to save the method or not is controlled from client side. We don't need to control it here.
        $savePaymentMethod = false;

        $this->doUpdateDonationFees(
            cardBrand: $cardBrand,
            donation: $donation,
            cardCountry: $cardBrand,
            savePaymentMethod: $savePaymentMethod,
        );
    }

    /**
     * Creates a payment intent at Stripe and records the PI ID against the donation.
     */
    public function createPaymentIntent(Donation $donation): void
    {
        Assertion::same($donation->getPsp(), 'stripe');

        if (!$donation->getCampaign()->isOpen()) {
            throw new CampaignNotOpen("Campaign {$donation->getCampaign()->getSalesforceId()} is not open");
        }

        $createPayload = [
            ...$donation->getStripeMethodProperties(),
            ...$donation->getStripeOnBehalfOfProperties(),
            'customer' => $donation->getPspCustomerId()?->stripeCustomerId,
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
}
