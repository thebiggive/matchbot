<?php

namespace MatchBot\Domain;

use Doctrine\DBAL\Exception\ServerException as DBALServerException;
use Doctrine\ORM\Exception\ORMException;
use MatchBot\Application\Assertion;
use MatchBot\Application\Matching\Adapter as MatchingAdapter;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Application\Notifier\StripeChatterInterface;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
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
use MatchBot\Domain\DomainException\RegularGivingCollectionEndPassed;
use MatchBot\Domain\DomainException\StripeAccountIdNotSetForAccount;
use MatchBot\Domain\DomainException\WrongCampaignType;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Ramsey\Uuid\UuidInterface;
use Random\Randomizer;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\StripeObject;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Envelope;
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
        private RoutableMessageBus $bus,
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
     * @throws WrongCampaignType
     * @throws \MatchBot\Client\NotFoundException
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
        StripeConfirmationTokenId $tokenId
    ): \Stripe\PaymentIntent {
        $this->updateDonationFeesFromConfirmationToken($tokenId, $donation);

        // We flush now to make sure the actual fees we're charging are recorded. If there's any DB error at this point
        // we prefer to crash without collecting the donation over collecting the donation without a proper record
        // or what we're charging.
        $this->entityManager->flush();

        $paymentIntentId = $donation->getTransactionId();
        Assertion::notNull($paymentIntentId);

        return $this->stripe->confirmPaymentIntent(
            $paymentIntentId,
            ['confirmation_token' => $tokenId->stripeConfirmationTokenId]
        );
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
     * @throws CampaignNotOpen
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
        $campaign = $donation->getCampaign();

        if (!$campaign->isOpen(new \DateTimeImmutable())) {
            throw new CampaignNotOpen("Campaign {$campaign->getSalesforceId()} is not open");
        }

        if ($donation->getMandate() === null && $campaign->isRegularGiving()) {
            throw new WrongCampaignType(
                "Campaign {$campaign->getSalesforceId()} does not accept one-off giving (regular-giving only)"
            );
        }

        if ($donation->getMandate() !== null && $campaign->isOneOffGiving()) {
            throw new WrongCampaignType(
                "Campaign {$campaign->getSalesforceId()} does not accept regular giving (one-off only)"
            );
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

        if ($campaign->isMatched()) {
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

        // Regular Giving enrolls donations with `DonationStatus::PreAuthorized`, which get Payment Intents later instead.
        if ($donation->getPsp() === 'stripe' && $donation->getDonationStatus() === DonationStatus::Pending) {
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

                // Else we found new Stripe info and can proceed
                $donation->setCampaign($campaign);
            }

            $this->createPaymentIntent($donation);
        }

        $this->bus->dispatch(new Envelope(DonationUpserted::fromDonation($donation)));
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
            $this->stripe->updatePaymentIntent($paymentIntentId, $updatedIntentData);
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

        if (!$donation->getCampaign()->isOpen(new \DateTimeImmutable())) {
            throw new CampaignNotOpen("Campaign {$donation->getCampaign()->getSalesforceId()} is not open");
        }

        try {
            $intent = $this->stripe->createPaymentIntent($donation->createStripePaymentIntentPayload());
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

    /**
     * Sets donation to cancelled in matchbot db, releases match funds, cancels payment in stripe, and updates
     * salesforce.
     *
     * Call this from inside a transaction and with a locked donation to avoid double releasing funds associated with
     * the donation.
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
                'Donation ID {$donation->getUuid()} could not be cancelled as {$donation->getDonationStatus()->value}'
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
                    throw new CouldNotCancelStripePaymentIntent();
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

        $donationUpserted = DonationUpserted::fromDonation($donation);
        $envelope = new Envelope($donationUpserted);
        $this->bus->dispatch($envelope);
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
        $donation = $this->donationRepository->findOneBy(['uuid' => $donationUUID]);

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

    public function confirmDonationWithSavedPaymentMethod(Donation $donation, StripePaymentMethodId $paymentMethod): void
    {
        $paymentIntentId = $donation->getTransactionId();
        Assertion::notNull($paymentIntentId);
        $paymentIntent = $this->stripe->confirmPaymentIntent(
            $paymentIntentId,
            ['payment_method' => $paymentMethod->stripePaymentMethodId]
        );

        if ($paymentIntent->status !== PaymentIntent::STATUS_SUCCEEDED) {
            // @todo-regular-giving: create a new db field on Donation - e.g. payment_attempt_count and update here
            // decide on a limit and log an error (or warning) if exceeded & perhaps auto-cancel the donation and/or
            // mandate.
        }
    }
}
