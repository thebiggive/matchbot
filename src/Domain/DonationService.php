<?php

namespace MatchBot\Domain;

use Doctrine\Common\Cache\CacheProvider;
use Doctrine\DBAL\Exception\ServerException as DBALServerException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use GuzzleHttp\Exception\ClientException;
use MatchBot\Application\Assertion;
use MatchBot\Application\Environment;
use MatchBot\Application\Matching\Adapter as MatchingAdapter;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Application\Notifier\StripeChatterInterface;
use MatchBot\Client\NotFoundException;
use MatchBot\Client\Stripe;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\DomainException\CampaignNotOpen;
use MatchBot\Domain\DomainException\CharityAccountLacksNeededCapaiblities;
use MatchBot\Domain\DomainException\CouldNotCancelStripePaymentIntent;
use MatchBot\Domain\DomainException\CouldNotMakeStripePaymentIntent;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\DomainException\DonationAlreadyFinalised;
use MatchBot\Domain\DomainException\DonationCreateModelLoadFailure;
use MatchBot\Domain\DomainException\MandateNotActive;
use MatchBot\Domain\DomainException\NoDonorAccountException;
use MatchBot\Domain\DomainException\PaymentIntentNotSucceeded;
use MatchBot\Domain\DomainException\RegularGivingCollectionEndPassed;
use MatchBot\Domain\DomainException\RegularGivingDonationToOldToCollect;
use MatchBot\Domain\DomainException\StripeAccountIdNotSetForAccount;
use MatchBot\Domain\DomainException\WrongCampaignType;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Ramsey\Uuid\UuidInterface;
use Random\Randomizer;
use Stripe\Card;
use Stripe\Charge;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\StripeObject;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\RateLimiter\Exception\RateLimitExceededException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class DonationService
{
    private const int MAX_RETRY_COUNT = 3;
    /**
     * Message excerpts that we expect to see sometimes from stripe on InvalidRequestExceptions. An exception
     * containing any of these strings should not generate an alarm.
     */
    public const array EXPECTED_STRIPE_INVALID_REQUEST_MESSAGES = [
        'The provided PaymentMethod has failed authentication',
        'You must collect the security code (CVC) for this card from the cardholder before you can use it',

        // When a donation is cancelled we update it to cancelled in the DB, which stops it being confirmed later. But
        // we can still get this error if the cancellation is too late to stop us attempting to confirm.
        // phpcs:ignore
        'This PaymentIntent\'s payment_method could not be updated because it has a status of canceled. You may only update the payment_method of a PaymentIntent with one of the following statuses: requires_payment_method, requires_confirmation, requires_action.',
        'The confirmation token has already been used to confirm a previous PaymentIntent',
        'This PaymentIntent\'s radar_options could not be updated because it has a status of canceled.',
        'This PaymentIntent\'s amount could not be updated because it has a status of canceled.',
        // phpcs:ignore
        'The parameter application_fee_amount cannot be updated on a PaymentIntent after a capture has already been made.',
    ];

    public const string STRIPE_DESTINATION_ACCOUNT_NEEDS_CAPABILITIES_MESSAGE = 'Your destination account needs to have at least one of the following capabilities enabled';


    /**
     * Previously donations were genereated from API requests in a separate class. That code has now been
     * consolidated into this class, but this closure is retained to allow donations to be set for test scenarios.
     * @var \Closure():Donation|null
     */
    private ?\Closure $fakeDonationProviderForTestUseOnly = null;

    public function __construct(
        private DonationRepository $donationRepository,
        private CampaignRepository $campaignRepository,
        private LoggerInterface $logger,
        private EntityManagerInterface $entityManager,
        private Stripe $stripe,
        private MatchingAdapter $matchingAdapter,
        private StripeChatterInterface|ChatterInterface $chatter,
        private ClockInterface $clock,
        private RateLimiterFactory $rateLimiterFactory,
        private DonorAccountRepository $donorAccountRepository,
        private RoutableMessageBus $bus,
        private DonationNotifier $donationNotifier,
        private FundRepository $fundRepository,
    ) {
    }

    /**
     * Creates a new pending ad-hoc donation.
     *
     * @param DonationCreate $donationData Details of the desired donation, as sent from the browser
     * @param string $pspCustomerId The Stripe customer ID of the donor
     * @param PersonId $donorId
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
     * @throws WrongCampaignType
     * @throws \MatchBot\Client\NotFoundException
     */
    public function createDonation(DonationCreate $donationData, string $pspCustomerId, PersonId $donorId): Donation
    {
        $this->rateLimiterFactory->create(key: $pspCustomerId)->consume()->ensureAccepted();

        try {
            $donation = $this->buildFromAPIRequest($donationData, $donorId);
        } catch (\UnexpectedValueException $e) {
            $message = 'Donation Create data initial model load';
            $this->logger->warning($message . ': ' . $e->getMessage());

            throw new DonationCreateModelLoadFailure(previous: $e);
        }

        if ($pspCustomerId !== $donation->getPspCustomerId()?->stripeCustomerId) {
            throw new \UnexpectedValueException(sprintf(
                'Route customer ID %s did not match %s in donation body',
                $pspCustomerId,
                $donation->getPspCustomerId()->stripeCustomerId ?? 'null'
            ));
        }

        if (!$donation->getCampaign()->isOpen($this->clock->now())) {
            throw new CampaignNotOpen("Campaign {$donation->getCampaign()->getSalesforceId()} is not open");
        }

        $this->enrollNewDonation($donation, attemptMatching: true);

        return $donation;
    }


    /**
     * @param DonationCreate $donationData
     * @return Donation
     * @throws \UnexpectedValueException if inputs invalid, including projectId being unrecognised
     * @throws NotFoundException
     */
    public function buildFromAPIRequest(DonationCreate $donationData, PersonId $donorId): Donation
    {
        // can't work out why one test (testSuccessWithMatchedCampaignAndInitialCampaignDuplicateError)
        // is failing if we don't pass useFake false here - the verison on develop seems to also return a
        // donation object passed in from the test case on the second invocation.

        if ($this->fakeDonationProviderForTestUseOnly) {
            return $this->fakeDonationProviderForTestUseOnly->__invoke();
        }

        if (!in_array($donationData->psp, ['stripe'], true)) {
            throw new \UnexpectedValueException(sprintf(
                'PSP %s is invalid',
                $donationData->psp,
            ));
        }

        $campaign = $this->campaignRepository->findOneBy(['salesforceId' => $donationData->projectId->value]);

        if (!$campaign) {
            // Fetch data for as-yet-unknown campaigns on-demand
            $this->logger->info("Loading unknown campaign ID {$donationData->projectId} on-demand");
            try {
                $campaign = $this->campaignRepository->pullNewFromSf($donationData->projectId);
            } catch (ClientException $exception) {
                $this->logger->error("Pull error for campaign ID {$donationData->projectId}: {$exception->getMessage()}");
                throw new \UnexpectedValueException('Campaign does not exist');
            }

            if ($this->clock->now() > new \DateTimeImmutable("Wed Apr 16 10:00:00 AM BST 2025")) {
                $this->logger->warning("Unexpected individual campaign {$campaign->getSalesforceId()} pulled from SF - should have been prewarmed");
            }

            $this->fundRepository->pullForCampaign($campaign);

            $this->entityManager->flush();

            // Because this case of campaigns being set up individually is relatively rare,
            // it is the one place outside of `UpdateCampaigns` where we clear the whole
            // result cache. It's currently the only user-invoked or single item place where
            // we do so.
            /**
             * @psalm-suppress DeprecatedMethod
             * @var CacheProvider $cacheDriver
             */
            $cacheDriver = $this->entityManager->getConfiguration()->getResultCacheImpl();
            $cacheDriver->deleteAll();
        }

        if ($donationData->currencyCode !== $campaign->getCurrencyCode()) {
            throw new \UnexpectedValueException(sprintf(
                'Currency %s is invalid for campaign',
                $donationData->currencyCode,
            ));
        }

        $donation = Donation::fromApiModel($donationData, $campaign, $donorId);
        $donation->deriveFees(null, null);

        return $donation;
    }

    /**
     *
     * We currently think that this retry logic is not useful as we are using it -
     * if the provided closure fails the first time then it will on all the retrys as well, since the Entity Manager
     * will have been closed from the first failure.
     *
     * However, if we're wrong and it is useful we will find out by an error log with "$actionName SUCCEEDED"
     *
     * @param \Closure $retryable The action to be executed and then retried if necessary
     * @param string $actionName The name of the action, used in logs.
     * @throws ORMException|DBALServerException if they're occurring when max retry count reached.
     */
    private function runWithPossibleRetry(\Closure $retryable, string $actionName): void
    {
        $retryCount = 0;
        while ($retryCount < self::MAX_RETRY_COUNT) {
            try {
                $retryable();
                if ($retryCount > 0) {
                    $this->logger->error(
                        "$actionName SUCCEEDED after $retryCount retry - retry process is not useless. " .
                        "See MAT-388. See info logs for original exception"
                    );
                }
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
     *
     * @throws ApiErrorException
     * @throws RegularGivingDonationToOldToCollect
     * @throws PaymentIntentNotSucceeded
     */
    public function confirmOnSessionDonation(
        Donation $donation,
        StripeConfirmationTokenId $tokenId
    ): \Stripe\PaymentIntent {
        $this->updateDonationFeesFromConfirmationToken($tokenId, $donation);

        // We flush now to make sure the actual fees we're charging are recorded. If there's any DB error at this point
        // we prefer to crash without collecting the donation over collecting the donation without a proper record
        // or what we're charging.
        $this->entityManager->flush();

        $paymentIntentId = $donation->getTransactionId();
        Assertion::notNull($paymentIntentId);

        $donation->checkPreAuthDateAllowsCollectionAt($this->clock->now());

        $updatedIntent = $this->stripe->confirmPaymentIntent(
            $paymentIntentId,
            [
                'confirmation_token' => $tokenId->stripeConfirmationTokenId,
            ]
        );

        if ($updatedIntent->status !== PaymentIntent::STATUS_SUCCEEDED) {
            throw new PaymentIntentNotSucceeded(
                $updatedIntent,
                "Payment Intent not succeded, status is {$updatedIntent->status}",
            );
        }

        return $updatedIntent;
    }

    /**
     * Trigger collection of funds from a pre-authorized donation associated with a regular giving mandate
     * @throws PaymentIntentNotSucceeded
     * @throws RegularGivingCollectionEndPassed
     * */
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

        $donation->checkPreAuthDateAllowsCollectionAt($this->clock->now());

        $campaign = $donation->getCampaign();
        if ($campaign->regularGivingCollectionIsEndedAt($this->clock->now())) {
            $collectionEnd = $campaign->getRegularGivingCollectionEnd();
            Assertion::notNull($collectionEnd);

            $donation->cancel();
            $mandate->campaignEnded();

            throw new RegularGivingCollectionEndPassed(
                "Cannot confirm a donation now, " .
                "regular giving collections for campaign {$campaign->getSalesforceId()} ended " .
                "at {$collectionEnd->format('Y-m-d')}"
            );
        }

        $paymentMethod = $donorAccount->getRegularGivingPaymentMethod();

        if ($paymentMethod === null) {
            throw new \MatchBot\Domain\NoRegularGivingPaymentMethod(
                "Cannot confirm donation {$donation->getUuid()} for " .
                "{$donorAccount->stripeCustomerId->stripeCustomerId}, no payment method"
            );
        }

        $this->confirmDonationWithSavedPaymentMethod($donation, $paymentMethod);
    }

    /**
     * Does multiple things required when a new donation is added to the system including:
     * - Checking that the campaign is open
     * - Allocating match funds to the donation
     * - Creating Stripe Payment intent
     *
     * @param bool $attemptMatching Whether to use match funds. Match funds will be withdrawn based on
     *                              availability or donation amount, which ever is smaller.
     * @throws CampaignNotOpen
     * @throws CharityAccountLacksNeededCapaiblities
     * @throws CouldNotMakeStripePaymentIntent
     * @throws DBALServerException
     * @throws ORMException
     * @throws StripeAccountIdNotSetForAccount
     * @throws WrongCampaignType
     * @throws NotFoundException
     */
    public function enrollNewDonation(Donation $donation, bool $attemptMatching, bool $dispatchUpdateMessage = true): void
    {
        $campaign = $donation->getCampaign();

        $campaign->checkIsReadyToAcceptDonation($donation, $this->clock->now());

        // Must persist before Stripe work to have ID available. Outer fn throws if all attempts fail.
        // @todo-MAT-388: remove runWithPossibleRetry if we determine its not useful and unwrap body of function below
        $this->runWithPossibleRetry(function () use ($donation) {
            $this->entityManager->persist($donation);
            $this->entityManager->flush();
        }, 'Donation Create persist before stripe work');

        if ($campaign->isMatched() && $attemptMatching) {
            $this->attemptFundingAllocation($donation);
        }


        // Regular Giving enrolls donations with `DonationStatus::PreAuthorized`, which get Payment Intents later instead.
        if ($donation->getPsp() === 'stripe' && $donation->getDonationStatus() === DonationStatus::Pending) {
            $this->loadCampaignsStripeId($campaign);
            $this->createPaymentIntent($donation);
        }

        if ($dispatchUpdateMessage) {
            $this->bus->dispatch(DonationUpserted::fromDonationEnveloped($donation));
        }
    }

    private function doUpdateDonationFees(
        CardBrand $cardBrand,
        Donation $donation,
        Country $cardCountry,
    ): void {

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

        $paymentIntentId = $donation->getTransactionId();
        if ($paymentIntentId !== null) {
            /** @psalm-suppress InvalidArgument  */
            $this->stripe->updatePaymentIntent($paymentIntentId, $updatedIntentData); // @phpstan-ignore argument.type
        }
    }

    private function updateDonationFeesFromConfirmationToken(
        StripeConfirmationTokenId $tokenId,
        Donation $donation
    ): void {
        $token = $this->stripe->retrieveConfirmationToken($tokenId);

        /** @var StripeObject $paymentMethodPreview */
        $paymentMethodPreview = $token->payment_method_preview;

        /** @var StripeObject $card */
        $card = $paymentMethodPreview['card'];

        Assertion::string($card['brand']);
        $cardBrand = CardBrand::from($card['brand']);
        $cardCountry = $card['country'];

        Assertion::string($cardCountry);
        $cardCountry = Country::fromAlpha2($cardCountry);

        $this->logger->info(sprintf(
            'Donation UUID %s has card brand %s and country %s',
            $donation->getUuid(),
            $cardBrand->value,
            $cardCountry,
        ));

        $this->doUpdateDonationFees(
            cardBrand: $cardBrand,
            donation: $donation,
            cardCountry: $cardCountry,
        );
    }

    /**
     * Creates a payment intent at Stripe and records the PI ID against the donation.
     */
    public function createPaymentIntent(Donation $donation): void
    {
        Assertion::same($donation->getPsp(), 'stripe');

        $now = $this->clock->now();

        $donation->checkPreAuthDateAllowsCollectionAt($now);

        try {
            $intent = $this->stripe->createPaymentIntent($donation->createStripePaymentIntentPayload());
        } catch (ApiErrorException $exception) {
            $message = $exception->getMessage();

            $accountLacksCapabilities = str_contains(
                $message,
                self::STRIPE_DESTINATION_ACCOUNT_NEEDS_CAPABILITIES_MESSAGE
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

        // @todo-MAT-388: remove runWithPossibleRetry if we determine its not useful and unwrap body of function below
        $this->runWithPossibleRetry(
            function () use ($donation) {
                $this->entityManager->persist($donation);
                $this->entityManager->flush();
            },
            'Donation Create persist after stripe work'
        );
    }

    /**
     * Sets donation to cancelled in matchbot db, releases match funds, cancels payment in stripe, and updates
     * salesforce.
     *
     * Call this from inside a transaction and with a locked donation to avoid double releasing funds associated with
     * the donation.
     * @throws CouldNotCancelStripePaymentIntent
     */
    public function cancel(Donation $donation): void
    {
        if ($donation->getDonationStatus() === DonationStatus::Cancelled) {
            $this->logger->info("Donation ID {$donation->getUuid()} was already Cancelled");

            return;
        }

        if ($donation->getDonationStatus()->isSuccessful()) {
            // If a donor uses browser back before loading the thank you page, it is possible for them to get
            // a Cancel dialog and send a cancellation attempt to this endpoint after finishing the donation.

            throw new DonationAlreadyFinalised(
                "Donation ID {$donation->getUuid()} could not be cancelled as {$donation->getDonationStatus()->value}"
            );
        }

        $this->logger->info("Cancelled ID {$donation->getUuid()}");

        $donation->cancel();

        // Save & flush early to reduce chance of lock conflicts.
        $this->save($donation);

        if ($donation->getCampaign()->isMatched()) {
            /** @psalm-suppress InternalMethod */
            $this->donationRepository->releaseMatchFunds($donation);
        }

        $transactionId = $donation->getTransactionId();
        if ($donation->getPsp() === 'stripe' && $transactionId !== null) {
            try {
                $this->stripe->cancelPaymentIntent($transactionId);
            } catch (ApiErrorException $exception) {
                /**
                 * As per the notes in {@see DonationRepository::releaseMatchFunds()}, we
                 * occasionally see double-cancels from the frontend. If Stripe tell us the
                 * Cancelled Donation's PI is canceled [note US spelling doesn't match our internal
                 * status], in all CC21 checks this seemed to be the situation.
                 *
                 * Instead of panicking in this scenario, our best available option is to log only a
                 * notice – we can still easily find these in the logs on-demand if we need to
                 * investigate proactively – and return 200 OK to the frontend.
                 */
                $doubleCancelMessage = 'You cannot cancel this PaymentIntent because it has a status of canceled.';
                $returnError = !str_starts_with($exception->getMessage(), $doubleCancelMessage);
                $stripeErrorLogLevel = $returnError ? LogLevel::ERROR : LogLevel::NOTICE;

                // We use the same log message, but reduce the severity in the case where we have detected
                // that it's unlikely to be a serious issue.
                $this->logger->log(
                    $stripeErrorLogLevel,
                    'Stripe Payment Intent cancel error: ' .
                    get_class($exception) . ': ' . $exception->getMessage()
                );

                if ($returnError) {
                    throw new CouldNotCancelStripePaymentIntent(previous: $exception);
                } // Else likely double-send -> fall through to normal return the donation as-is.
            }
        }
    }

    /**
     * Save donation in all cases. Also send updated donation data to Salesforce, *if* we know
     * enough to do so successfully.
     *
     * Assumes it will be called only after starting a transaction pre-donation-select.
     *
     * @param Donation $donation
     */
    public function save(Donation $donation): void
    {
        // SF push and the corresponding DB persist only happens when names are already set.
        // There could be other data we need to save before that point, e.g. comms
        // preferences, so to be safe we persist here first.
        $this->entityManager->persist($donation);
        $this->entityManager->flush();

        if (!$donation->hasEnoughDataForSalesforce()) {
            return;
        }

        $this->bus->dispatch(DonationUpserted::fromDonationEnveloped($donation));
    }

    /**
     * InvalidRequestException can have various possible messages. If it's one we've seen before that we don't believe
     * indicates a bug or failure in matchbot then we just send an error message to the client. If it's something we
     * haven't seen before or didn't expect then we will also generate an alarm for Big Give devs to deal with.
     * @param InvalidRequestException $exception
     * @return bool
     */
    public static function errorMessageFromStripeIsExpected(InvalidRequestException $exception): bool
    {
        $exceptionMessage = $exception->getMessage();

        foreach (DonationService::EXPECTED_STRIPE_INVALID_REQUEST_MESSAGES as $expectedMessage) {
            if (str_contains($exceptionMessage, $expectedMessage)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Within a transaction, loads a donation from the DB and then releases any funding matched to it.
     *
     * If the matching for the donation has already been released (e.g. by another process after the donationId
     * was found but before we lock the donation here) then this should be a no-op because Donation::fundingWithdrawals
     * are eagerly loaded with the donation so will be empty.
     */
    public function releaseMatchFundsInTransaction(UuidInterface $donationId): void
    {
        $this->entityManager->wrapInTransaction(function () use ($donationId) {
            $donation = $this->donationRepository->findAndLockOneByUUID($donationId);
            Assertion::notNull($donation);

            $this->donationRepository->releaseMatchFunds($donation);

            $this->entityManager->flush();
        });
    }

    public function donationAsApiModel(UuidInterface $donationUUID): array
    {
        $donation = $this->donationRepository->findOneByUUID($donationUUID);

        if (!$donation) {
            throw new DomainRecordNotFoundException('Donation not found');
        }

        return $donation->toFrontEndApiModel();
    }

    public function findAllCompleteForCustomerAsAPIModels(StripeCustomerId $stripeCustomerId): array
    {
        $donations = $this->donationRepository->findAllCompleteForCustomer($stripeCustomerId);

        return array_map(fn(Donation $donation) => $donation->toFrontEndApiModel(), $donations);
    }

    /**
     * @throws PaymentIntentNotSucceeded
     * */
    public function confirmDonationWithSavedPaymentMethod(Donation $donation, StripePaymentMethodId $paymentMethod): void
    {
        $paymentIntentId = $donation->getTransactionId();
        Assertion::notNull($paymentIntentId);
        $paymentIntent = $this->stripe->confirmPaymentIntent(
            $paymentIntentId,
            [
                'payment_method' => $paymentMethod->stripePaymentMethodId,
            ]
        );

        $this->logger->info("PaymentIntent: {$paymentIntent->toJSON()}");

        if ($paymentIntent->status !== PaymentIntent::STATUS_SUCCEEDED) {
            // @todo-regular-giving-mat-407: create a new db field on Donation - e.g. payment_attempt_count and update here
            // decide on a limit and log an error (or warning) if exceeded & perhaps auto-cancel the donation and/or
            // mandate.

            throw new PaymentIntentNotSucceeded(
                $paymentIntent,
                "Payment Intent not succeded, status is {$paymentIntent->status}",
            );
        }
    }

    /**
     * For use when we have confirmed a donation and need to update it synchronously before further processing -
     * i.e. to know whether to go on to start a regular giving agreement if it was sucessful.
     */
    public function queryStripeToUpdateDonationStatus(Donation $donation): void
    {
        $paymentIntentID = $donation->getTransactionId();
        if ($paymentIntentID === null) {
            return;
        }

        $paymentIntent = $this->stripe->retrievePaymentIntent($paymentIntentID);

        if ($paymentIntent->status !== PaymentIntent::STATUS_SUCCEEDED) {
            return;
        }

        $charge = $paymentIntent->latest_charge;
        if ($charge === null) {
            return;
        }

        $charge = $this->stripe->retrieveCharge((string) $charge);

        if ($charge->status !== Charge::STATUS_SUCCEEDED) {
            return;
        }

        $this->updateDonationStatusFromSuccessfulCharge($charge, $donation);
    }

    public function updateDonationStatusFromSuccessfulCharge(Charge $charge, Donation $donation): void
    {
        $this->logger->info('updating donation from charge: ' . $charge->toJSON());

        /**
         * @psalm-suppress MixedMethodCall
         * @var array|Card|null $card
         */
        $card = $charge->payment_method_details?->toArray()['card'] ?? null;
        if (is_array($card)) {
            /** @var Card $card */
            $card = (object)$card; // @phpstan-ignore varTag.nativeType
        }

        $cardBrand = CardBrand::fromNameOrNull($card?->brand);
        $cardCountry = Country::fromAlpha2OrNull($card?->country);
        $balanceTransaction = (string)$charge->balance_transaction;

        // To give *simulated* webhooks, for Donation API-only load tests, an easy way to complete
        // without crashing, we support skipping the original fee derivation by omitting
        // `balance_transaction`. Real stripe charge.succeeded webhooks should always have
        // an associated Balance Transaction.
        if (!empty($balanceTransaction)) {
            $originalFeeFractional = $this->getOriginalFeeFractional(
                $balanceTransaction,
                $donation->currency()->isoCode(),
            );
        } else {
            $originalFeeFractional = $donation->getOriginalPspFee();
        }

        $donation->collectFromStripeCharge(
            chargeId: $charge->id,
            totalPaidFractional: $charge->amount,
            transferId: (string)$charge->transfer,
            cardBrand: $cardBrand,
            cardCountry: $cardCountry,
            originalFeeFractional: (string)$originalFeeFractional,
            chargeCreationTimestamp: $charge->created,
        );

        $dateTimeImmutable = $donation->getCollectedAt();

        if (
            ! $donation->isRegularGiving() &&
            ($dateTimeImmutable > new \DateTimeImmutable(Donation::MAT_400_ENABLE_TIMESTAMP))
        ) {
            // Regular giving donors get an email confirming the setup of the mandate, but not an email for
            // each individual donation.
            $this->donationNotifier->notifyDonorOfDonationSuccess(
                donation: $donation,
                sendRegisterUri: $this->shouldInviteRegistration($donation),
            );
        }
    }


    private function getOriginalFeeFractional(string $balanceTransactionId, string $expectedCurrencyCode): int
    {
        $txn = $this->stripe->retrieveBalanceTransaction($balanceTransactionId);

        if (count($txn->fee_details) !== 1) {
            $this->logger->warning(sprintf(
                'StripeChargeUpdate::getFee: Unexpected composite fee with %d parts: %s',
                count($txn->fee_details),
                json_encode($txn->fee_details, \JSON_THROW_ON_ERROR),
            ));
        }

        /**
         * See https://docs.stripe.com/api/balance_transactions/object#balance_transaction_object-fee_details
         * @var object{currency: string, type: string} $feeDetail
         * // @phpstan-ignore varTag.type
         */
        $feeDetail = $txn->fee_details[0];

        if ($feeDetail->currency !== strtolower($expectedCurrencyCode)) {
            // `fee` should presumably still be in parent account's currency, so don't bail out.
            $this->logger->warning(sprintf(
                'StripeChargeUpdate::getFee: Unexpected fee currency %s',
                $feeDetail->currency,
            ));
        }

        if ($feeDetail->type !== 'stripe_fee') {
            $this->logger->warning(sprintf(
                'StripeChargeUpdate::getFee: Unexpected type %s',
                $feeDetail->type,
            ));
        }

        return $txn->fee;
    }

    public function attemptFundingAllocation(Donation $donation): void
    {
        // @todo-MAT-388: remove runWithPossibleRetry if we determine its not useful and unwrap body of function below
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

                    $this->entityManager->flush();

                    throw $t;
                }
            },
            'allocate match funds'
        );
    }

    /**
     * Checks that a campaign has a Stripe Account ID and if not attempts to find one in SF.
     *
     * @throws StripeAccountIdNotSetForAccount
     * @todo consider if any of this method is required - or if we do or can ensure that Stripe Account ID is always
     * set in matchbot before the donation is attempted.
     */
    private function loadCampaignsStripeId(Campaign $campaign): void
    {
        $stripeAccountId = $campaign->getCharity()->getStripeAccountId();
        if ($stripeAccountId === null || $stripeAccountId === '') {
            // Try re-pulling in case charity has very recently onboarded with for Stripe.
            $this->campaignRepository->updateFromSf($campaign);

            // If still empty, error out
            $stripeAccountId = $campaign->getCharity()->getStripeAccountId();
            if ($stripeAccountId === null || $stripeAccountId === '') {
                $this->logger->error(sprintf(
                    'Stripe Payment Intent create error: Stripe Account ID not set for Account %s',
                    $campaign->getCharity()->getSalesforceId(),
                ));
                throw new StripeAccountIdNotSetForAccount();
            }
        }
    }

    private function shouldInviteRegistration(Donation $donation): bool
    {
        $donorId = $donation->getDonorId();
        if (!$donorId) {
            // must be an old donation
            return false;
        }

        // In most cases if there is already a donor account then identity wouldn't have sent us a token so
        // we wouldn't be able to invite registration here anway. But we need this check in case there is a recent
        // token from just before the donor registered their account very recently.

        // Identity sends key info for MB DonorAccount iff a password was set via \Messages\Person, so
        // we can use record existence to decide whether to send a register link.
        return $this->donorAccountRepository->findByPersonId($donorId) === null;
    }

    /**
     * @param null|Closure():Donation $fakeDonationProviderForTestUseOnly = null;
     */
    public function setFakeDonationProviderForTestUseOnly(?\Closure $fakeDonationProviderForTestUseOnly): void
    {
        Assertion::true(Environment::current() === Environment::Test);
        $this->fakeDonationProviderForTestUseOnly = $fakeDonationProviderForTestUseOnly;
    }
}
