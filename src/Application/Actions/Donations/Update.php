<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Actions\ActionError;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\HttpModels;
use MatchBot\Application\Messenger\DonationStateUpdated;
use MatchBot\Client\Stripe;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\PaymentMethodType;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Slim\Exception\HttpBadRequestException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\PaymentIntent;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\SerializerInterface;
use TypeError;

/**
 * Apply a donor-authorised PUT action to update an existing donation. The purpose
 * of the update can be to cancel the donation, add more details to it, or
 * confirm it if no further method info is needed (e.g. `customer_balance`
 * settlements).
 */
class Update extends Action
{
    private const MAX_UPDATE_RETRY_COUNT = 4;

    #[Pure]
    public function __construct(
        private DonationRepository $donationRepository,
        private EntityManagerInterface $entityManager,
        private SerializerInterface $serializer,
        private Stripe $stripe,
        LoggerInterface $logger,
        private ClockInterface $clock,
        private RoutableMessageBus $bus,
    ) {
        parent::__construct($logger);
    }

    /**
     * @return Response
     * @throws DomainRecordNotFoundException on missing donation
     * @throws ApiErrorException if Stripe Payment Intent confirm() fails, other than because of a
     *                           missing payment method.
     */
    protected function action(Request $request, Response $response, array $args): Response
    {
        if (empty($args['donationId']) || ! is_string($args['donationId'])) {
            // When MatchBot made a donation, this is now a UUID
            throw new DomainRecordNotFoundException('Missing donation ID');
        }

        $body = (string) $request->getBody();

        try {
            /** @var HttpModels\Donation $donationData */
            $donationData = $this->serializer->deserialize(
                $body,
                HttpModels\Donation::class,
                'json'
            );
        } catch (UnexpectedValueException | TypeError $exception) {
            // UnexpectedValueException is the Serializer one, not the global one
            $this->logger->info("Donation Update non-serialisable payload was: $body");

            $message = 'Donation Update data deserialise error';
            $exceptionType = get_class($exception);

            return $this->validationError(
                $response,
                "$message: $exceptionType - {$exception->getMessage()}",
                $message,
                empty($body), // Suspected bot / junk traffic sometimes sends blank payload.
            );
        }

        if (
            getenv('APP_ENV') !== 'production' &&
            str_starts_with($donationData->donorName?->first ?? '', 'Please throw')
        ) {
            $this->logger->critical("Testing a critical log message for BG2-2297");
            throw new \Exception("{$donationData->donorName?->first} requested an exception for test purposes");
        }

        if (!isset($donationData->status)) {
            return $this->validationError(
                $response,
                "Donation ID {$args['donationId']} could not be updated with missing status",
                'New status is required'
            );
        }

        // Lock wait errors on the select OR update mean we should try the transaction again
        // after a short pause, up to self::MAX_UPDATE_RETRY_COUNT times.
        $retryCount = 0;
        while ($retryCount < self::MAX_UPDATE_RETRY_COUNT) {
            $this->entityManager->beginTransaction();

            try {
                $donation = $this->donationRepository->findAndLockOneByUUID($args['donationId']);

                if (!$donation) {
                    $this->entityManager->rollback();

                    throw new DomainRecordNotFoundException('Donation not found');
                }

                if (
                    $donationData->status !== DonationStatus::Cancelled->value &&
                    $donationData->status !== $donation->getDonationStatus()->value
                ) {
                    $this->entityManager->rollback();

                    return $this->validationError(
                        $response,
                        "Donation ID {$args['donationId']} could not be set to status {$donationData->status}",
                        'Status update is only supported for cancellation'
                    );
                }

                if (
                    $donationData->autoConfirmFromCashBalance &&
                    $donation->getPaymentMethodType() !== PaymentMethodType::CustomerBalance
                ) {
                    $this->entityManager->rollback();

                    // Log a warning to more easily spot occurrences in dashboards.
                    $methodSummary = $donation->getPaymentMethodType()?->value ?? '[null]';
                    $this->logger->warning(
                        "Donation ID {$args['donationId']} auto-confirm attempted with '$methodSummary' payment method",
                    );

                    return $this->validationError(
                        $response,
                        "Donation ID {$args['donationId']} could not be auto-confirmed",
                        'Processing incomplete. Please refresh and check your donation funds balance'
                    );
                }

                if ($donationData->status === DonationStatus::Cancelled->value) {
                    return $this->cancel($donation, $response, $args);
                }

                return $this->addData($donation, $donationData, $args, $response, $request);
            } catch (\UnexpectedValueException $e) {
                return $this->validationError(
                    $response,
                    $e->getMessage()
                );
            } catch (LockWaitTimeoutException $lockWaitTimeoutException) {
                $this->logger->warning(sprintf(
                    'Caught LockWaitTimeoutException in Update for donation %s, retry count %d',
                    $args['donationId'],
                    $retryCount,
                ));

                // pause for 0.1, 0.2, 0.4 and then 0.8s before giving up.
                $seconds = (0.1 * (2 ** $retryCount));
                $this->clock->sleep($seconds);
                $retryCount++;

                $this->entityManager->rollback();
            } catch (InvalidRequestException $invalidRequestException) {
                if (
                    str_starts_with(
                        haystack: $invalidRequestException->getMessage(),
                        needle: "This PaymentIntent's amount could not be updated because it has a status of canceled",
                    )
                ) {
                    \assert(
                        isset($donation),
                        "If we've got as far as Stripe throwing an exception we must have a Donation"
                    );

                    $this->logger->warning(sprintf(
                        'Stripe rejected payment intent update as PI was cancelled, presumably by stripe' .
                        ' itself very recently. Donation UUID %s',
                        $donation->getUuid(),
                    ));
                    $this->entityManager->rollback();

                    return $this->validationError(
                        $response,
                        "Donation ID {$args['donationId']} could not be updated",
                        'This donation payment intent has been cancelled. You may wish to start a fresh donation.'
                    );
                }

                throw $invalidRequestException;
            }
        }

        throw new \Exception(
            "Retry count exceeded trying to update donation {$args['donationId']} , retried $retryCount times",
        );
    }

    /**
     * Assumes it will be called only after starting a transaction pre-donation-select.
     * @throws InvalidRequestException
     * @throws ApiErrorException if confirm() fails other than because of a missing payment method.
     * @throws \UnexpectedValueException
     */
    private function addData(
        Donation $donation,
        HttpModels\Donation $donationData,
        array $args,
        Response $response,
        Request $request
    ): Response {
        // If the app tries to PUT with a different amount, something has gone very wrong and we should
        // explicitly fail instead of ignoring that field.
        if (bccomp($donation->getAmount(), (string) $donationData->donationAmount) !== 0) {
            $this->entityManager->rollback();

            return $this->validationError(
                $response,
                "Donation ID {$args['donationId']} amount did not match",
                'Amount updates are not supported'
            );
        }

        foreach (['optInCharityEmail', 'optInTbgEmail'] as $requiredBoolean) {
            if (!isset($donationData->$requiredBoolean)) {
                $this->entityManager->rollback();

                return $this->validationError($response, sprintf(
                    "Required boolean field '%s' not set",
                    $requiredBoolean,
                ), null, true);
            }
        }

        if ($donationData->currencyCode === 'GBP' && !isset($donationData->giftAid)) {
            $this->entityManager->rollback();

            return $this->validationError($response, "Required boolean field 'giftAid' not set", null, true);
        }

        if ($donation->getDonationStatus() === DonationStatus::Cancelled) {
            // this guard clause is technically not needed, and impossible to unit test, as this is covered by two
            // previous clauses:
            //
            // - if donation from the DB is cancelled and the request sends a non-cancelled status we bail out all other
            //      status changes are not supported
            // - if donation from the DB is cancelled and the request is sending a cancelled status as well then we do
            //      nothing.
            //
            // But worth keeping here IMHO just in case the other parts change.

            $this->entityManager->rollback();

            return $this->validationError($response, "Can not update cancelled donation", null, true);
        }

        // These 3 fields are currently set up early in the journey, but are harmless and more flexible
        // to support setting later. The frontend will probably leave these set and do a no-op update
        // when it makes the PUT call.
        if (isset($donationData->countryCode)) {
            $donation->setDonorCountryCode(strtoupper($donationData->countryCode));
        }
        if (isset($donationData->feeCoverAmount)) {
            $donation->setFeeCoverAmount((string) $donationData->feeCoverAmount);
        }
        if (isset($donationData->tipAmount)) {
            try {
                $donation->setTipAmount($donationData->tipAmount);
            } catch (\UnexpectedValueException $exception) {
                $this->entityManager->rollback();

                return $this->validationError(
                    $response,
                    sprintf("Invalid tipAmount '%s'", $donationData->tipAmount),
                    $exception->getMessage(),
                    false,
                );
            }
        }

        // All calls using the new two-step approach should set all the remaining values in this
        // method every time they `addData()`.
        try {
            $donation->update(
                giftAid: $donationData->giftAid ?? false,
                tipGiftAid: $donationData->tipGiftAid ?? $donationData->giftAid,
                donorHomeAddressLine1: $donationData->homeAddress,
                donorHomePostcode: $donationData->homePostcode,
                donorName: $donationData->donorName,
                donorEmailAddress: $donationData->emailAddress,
                tbgComms: $donationData->optInTbgEmail,
                charityComms: $donationData->optInCharityEmail,
                championComms: $donationData->optInChampionEmail,
                donorBillingPostcode: $donationData->billingPostalAddress
            );
        } catch (\UnexpectedValueException $exception) {
            return $this->validationError(
                $response,
                $exception->getMessage(),
                $exception->getMessage(),
                false,
            );
        }


        // currently this can't change the fee from what it was when donation entity was created, but
        // we call deriveFees here for consistency with the Create action, in case the derivation logic changes to
        // depend on something we do mutate in the donation. But if this is a card payment it will be called again in
        // the `confirm` action.
        $donation->deriveFees(null, null);

        if ($donation->getPsp() === 'stripe') {
            try {
                $this->updatePaymentIntent($donation);
            } catch (RateLimitException $exception) {
                if ($exception->getStripeCode() !== 'lock_timeout') {
                    throw $exception; // Only re-try when object level lock failed.
                }

                $this->logger->info(sprintf(
                    'Stripe lock "rate limit" hit while updating payment intent for donation %s – retrying in 1s...',
                    $donation->getUuid(),
                ));
                $this->clock->sleep(1);

                try {
                    $this->updatePaymentIntent($donation);
                } catch (RateLimitException $retryException) {
                    $this->logger->error(sprintf(
                        'Stripe Payment Intent update error from lock "rate limit" on %s, %s [%s]: %s',
                        $donation->getUuid(),
                        get_class($retryException),
                        $retryException->getStripeCode(),
                        $retryException->getMessage(),
                    ));
                    $error = new ActionError(ActionError::SERVER_ERROR, 'Could not update Stripe Payment Intent [C]');
                    $this->entityManager->rollback();

                    return $this->respond($response, new ActionPayload(500, null, $error));
                } catch (ApiErrorException $retryException) {
                    $responseIfFinal = $this->handleGeneralStripeError($retryException, $donation, $response);

                    if ($responseIfFinal) {
                        return $responseIfFinal;
                    }
                }
            } catch (ApiErrorException $exception) {
                $responseIfFinal = $this->handleGeneralStripeError($exception, $donation, $response);

                if ($responseIfFinal) {
                    return $responseIfFinal;
                }
            }

            if ($donationData->autoConfirmFromCashBalance) {
                try {
                    $confirmedIntent = $this->stripe->confirmPaymentIntent($donation->getTransactionId());

                    /** @var string|null $nextActionType */
                    $nextActionType = null;
                    if ($confirmedIntent->status === PaymentIntent::STATUS_REQUIRES_ACTION) {
                        $nextActionType = (string) $confirmedIntent->next_action?->type;
                    }

                    $isDonationToBGRequiringBankTransfer =
                        $confirmedIntent->status === PaymentIntent::STATUS_REQUIRES_ACTION &&
                        $nextActionType === 'display_bank_transfer_instructions' &&
                        $donation->getCampaign()->getCampaignName() === 'Big Give General Donations';

                    $statusIsNeitherSuccessNorTipWithCreditsNextAction =
                        $confirmedIntent->status !== PaymentIntent::STATUS_SUCCEEDED &&
                        !$isDonationToBGRequiringBankTransfer;

                    if ($statusIsNeitherSuccessNorTipWithCreditsNextAction) {
                        // As this is autoConfirmFromCashBalance and we only expect people to make such donations if
                        // they have a sufficient balance we expect PI to succeed synchronosly. If it didn't we don't
                        // want to leave the PI around to succeed later when the donor might not be expecting it.
                        $this->cancelDonationAndPaymentIntent($donation, $confirmedIntent);
                        throw new HttpBadRequestException(
                            $request,
                            "Status was {$confirmedIntent->status}, expected " . PaymentIntent::STATUS_SUCCEEDED
                        );
                    }
                } catch (InvalidRequestException $exception) {
                    // Currently a typical Update call which auto-confirms is being made for just
                    // that purpose. So our safest options are to return a 500 and roll back any
                    // database changes.
                    $this->entityManager->rollback();

                    // To help analyse it quicker we handle the specific auto-confirm API failure we've
                    // seen before with a distinct message, but both options give the client an HTTP 500,
                    // as we expect neither with our updated guard conditions.
                    if (
                        str_starts_with(
                            $exception->getMessage(),
                            "You cannot confirm this PaymentIntent because it's missing a payment method"
                        )
                    ) {
                        $this->logger->error(sprintf(
                            'Stripe Payment Intent for donation ID %s was missing a payment method, so we ' .
                                'could not confirm it',
                            $donation->getUuid(),
                        ));
                        $error = new ActionError(ActionError::SERVER_ERROR, 'Could not confirm Stripe Payment Intent');

                        return $this->respond($response, new ActionPayload(500, null, $error));
                    }

                    throw $exception;
                }
            }
        }

        $this->save($donation);

        return $this->respondWithData($response, $donation->toApiModel());
    }

    private function cancel(Donation $donation, Response $response, array $args): Response
    {
        if ($donation->getDonationStatus() === DonationStatus::Cancelled) {
            $this->logger->info("Donation ID {$args['donationId']} was already Cancelled");
            $this->entityManager->rollback();

            return $this->respondWithData($response, $donation->toApiModel());
        }

        if ($donation->getDonationStatus()->isSuccessful()) {
            // If a donor uses browser back before loading the thank you page, it is possible for them to get
            // a Cancel dialog and send a cancellation attempt to this endpoint after finishing the donation.
            $this->entityManager->rollback();

            return $this->validationError(
                $response,
                "Donation ID {$args['donationId']} could not be cancelled as {$donation->getDonationStatus()->value}",
                'Donation already finalised'
            );
        }

        $this->logger->info("Donor cancelled ID {$args['donationId']}");

        $donation->cancel();

        // Save & flush early to reduce chance of lock conflicts.
        $this->save($donation);

        if ($donation->getCampaign()->isMatched()) {
            $this->donationRepository->releaseMatchFunds($donation);
        }

        if ($donation->getPsp() === 'stripe') {
            try {
                $this->stripe->cancelPaymentIntent($donation->getTransactionId());
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
                    $error = new ActionError(ActionError::SERVER_ERROR, 'Could not cancel Stripe Payment Intent');

                    return $this->respond($response, new ActionPayload(500, null, $error));
                } // Else likely double-send -> fall through to normal 200 OK response and return the donation as-is.
            }
        }

        return $this->respondWithData($response, $donation->toApiModel());
    }

    /**
     * Save donation in all cases. Also send updated donation data to Salesforce, *if* we know
     * enough to do so successfully.
     *
     * Assumes it will be called only after starting a transaction pre-donation-select.
     *
     * @param Donation $donation
     */
    private function save(Donation $donation): void
    {
        // SF push and the corresponding DB persist only happens when names are already set.
        // There could be other data we need to save before that point, e.g. comms
        // preferences, so to be safe we persist here first.
        $this->entityManager->persist($donation);
        $this->entityManager->flush();
        $this->entityManager->commit();

        if (!$donation->hasEnoughDataForSalesforce()) {
            return;
        }

        $this->bus->dispatch(new Envelope(DonationStateUpdated::fromDonation($donation)));
    }

    /**
     * @throws RateLimitException if e.g. Stripe had locked the PI while processing.
     * @throws InvalidRequestException - for example if the payment intent has just been cancelled by stripe.
     */
    private function updatePaymentIntent(Donation $donation): void
    {
        $this->stripe->updatePaymentIntent($donation->getTransactionId(), [
            'amount' => $donation->getAmountFractionalIncTip(),
            'currency' => strtolower($donation->getCurrencyCode()),
            'metadata' => [
                /**
                 * Note that we don't re-set keys that can't change, like `charityId`.
                 * See the counterpart in {@see Create::action()} too.
                 */
                'coreDonationGiftAid' => $donation->hasGiftAid(),
                'feeCoverAmount' => $donation->getFeeCoverAmount(),
                'matchedAmount' => $donation->getFundingWithdrawalTotal(),
                'optInCharityEmail' => $donation->getCharityComms(),
                'optInTbgEmail' => $donation->getTbgComms(),
                'salesforceId' => $donation->getSalesforceId(),
                'stripeFeeRechargeGross' => $donation->getCharityFeeGross(),
                'stripeFeeRechargeNet' => $donation->getCharityFee(),
                'stripeFeeRechargeVat' => $donation->getCharityFeeVat(),
                'tbgTipGiftAid' => $donation->hasTipGiftAid(),
                'tipAmount' => $donation->getTipAmount(),
            ],
            // See https://stripe.com/docs/connect/destination-charges#application-fee
            // Update the fee amount incase the final charge was from
            // a Non EU / Amex card where fees are varied.
            'application_fee_amount' => $donation->getAmountToDeductFractional(),
            // Note that `on_behalf_of` is set up on create and is *not allowed* on update.
        ]);
    }

    /**
     * @return ?Response Response to send client, if appropriate. HTTP 500.
     */
    private function handleGeneralStripeError(
        ApiErrorException $exception,
        Donation $donation,
        Response $response
    ): ?Response {
        $alreadyCapturedMsg = 'The parameter application_fee_amount cannot be updated on a PaymentIntent ' .
            'after a capture has already been made.';
        if (
            $exception instanceof InvalidRequestException &&
            str_starts_with($exception->getMessage(), $alreadyCapturedMsg)
        ) {
            $latestPI = $this->stripe->retrievePaymentIntent($donation->getTransactionId());
            if ($latestPI->application_fee_amount === $donation->getAmountToDeductFractional()) {
                $noFeeChangeMessage = 'Stripe Payment Intent update ignored after capture; no fee ' .
                    'change on %s, %s [%s]: %s';
                $this->logger->info(sprintf(
                    $noFeeChangeMessage,
                    $donation->getUuid(),
                    get_class($exception),
                    $exception->getStripeCode(),
                    $exception->getMessage(),
                ));
                // Fall through to normal save and success response.
            } else {
                $this->logger->error(sprintf(
                    'Stripe Payment Intent update after capture; fee change from %d to %d ' .
                    'not possible on %s, %s [%s]: %s',
                    (int) $latestPI->application_fee_amount,
                    $donation->getAmountToDeductFractional(),
                    $donation->getUuid(),
                    get_class($exception),
                    $exception->getStripeCode(),
                    $exception->getMessage(),
                ));
                // Quickly distinguish fee change case with response message suffix.
                $error = new ActionError(
                    ActionError::SERVER_ERROR,
                    'Could not update Stripe Payment Intent [A]',
                );
                $this->entityManager->rollback();

                return $this->respond($response, new ActionPayload(500, null, $error));
            }
        } else {
            $this->logger->error(sprintf(
                'Stripe Payment Intent update error on %s, %s [%s]: %s',
                $donation->getUuid(),
                get_class($exception),
                $exception->getStripeCode(),
                $exception->getMessage(),
            ));
            $error = new ActionError(ActionError::SERVER_ERROR, 'Could not update Stripe Payment Intent [B]');
            $this->entityManager->rollback();

            return $this->respond($response, new ActionPayload(500, null, $error));
        }

        return null;
    }

    private function cancelDonationAndPaymentIntent(Donation $donation, PaymentIntent $confirmedPaymentIntent): void
    {
        $this->stripe->cancelPaymentIntent($donation->getTransactionId());
        $donation->cancel();
        $this->entityManager->flush();

        $this->logger->warning(
            "Cancelled funded donation #{$donation->getId()} due to non-success on confirmation attempt status " .
            "{$confirmedPaymentIntent->status}. May be insufficent funds in donor account."
        );
    }
}
