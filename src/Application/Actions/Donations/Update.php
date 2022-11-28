<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Actions\ActionError;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\HttpModels;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\RateLimitException;
use Stripe\StripeClient;
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
    #[Pure]
    public function __construct(
        private DonationRepository $donationRepository,
        private EntityManagerInterface $entityManager,
        private SerializerInterface $serializer,
        private StripeClient $stripeClient,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * @return Response
     * @throws DomainRecordNotFoundException
     */
    protected function action(): Response
    {
        if (empty($this->args['donationId'])) { // When MatchBot made a donation, this is now a UUID
            throw new DomainRecordNotFoundException('Missing donation ID');
        }

        /** @var Donation $donation */
        $this->entityManager->beginTransaction();

        $donation = $this->donationRepository->findOneBy(['uuid' => $this->args['donationId']]);

        if (!$donation) {
            $this->entityManager->rollback();

            throw new DomainRecordNotFoundException('Donation not found');
        }

        $body = (string) $this->request->getBody();

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

            $this->entityManager->rollback();

            return $this->validationError(
                "$message: $exceptionType - {$exception->getMessage()}",
                $message,
                empty($body), // Suspected bot / junk traffic sometimes sends blank payload.
            );
        }

        if (!isset($donationData->status)) {
            $this->entityManager->rollback();

            return $this->validationError(
                "Donation ID {$this->args['donationId']} could not be updated with missing status",
                'New status is required'
            );
        }

        if ($donationData->status === 'Cancelled') {
            return $this->cancel($donation);
        }

        if ($donationData->status !== $donation->getDonationStatus()) {
            $this->entityManager->rollback();

            return $this->validationError(
                "Donation ID {$this->args['donationId']} could not be set to status {$donationData->status}",
                'Status update is only supported for cancellation'
            );
        }

        $response = $this->addData($donation, $donationData);

        return $response;
    }

    /**
     * Assumes it will be called only after starting a transaction pre-donation-select.
     */
    private function addData(Donation $donation, HttpModels\Donation $donationData): Response
    {
        // If the app tries to PUT with a different amount, something has gone very wrong and we should
        // explicitly fail instead of ignoring that field.
        if (bccomp($donation->getAmount(), (string) $donationData->donationAmount) !== 0) {
            $this->entityManager->rollback();

            return $this->validationError(
                "Donation ID {$this->args['donationId']} amount did not match",
                'Amount updates are not supported'
            );
        }

        foreach (['optInCharityEmail', 'optInTbgEmail'] as $requiredBoolean) {
            if (!isset($donationData->$requiredBoolean)) {
                $this->entityManager->rollback();

                return $this->validationError(sprintf(
                    "Required boolean field '%s' not set",
                    $requiredBoolean,
                ), null, true);
            }
        }

        if ($donationData->currencyCode === 'GBP' && !isset($donationData->giftAid)) {
            $this->entityManager->rollback();

            return $this->validationError("Required boolean field 'giftAid' not set", null, true);
        }

        // These 3 fields are currently set up early in the journey, but are harmless and more flexible
        // to support setting later. The frontend will probably leave these set and do a no-op update
        // when it makes the PUT call.
        if (isset($donationData->countryCode)) {
            $donation->setDonorCountryCode($donationData->countryCode);
        }
        if (isset($donationData->feeCoverAmount)) {
            $donation->setFeeCoverAmount((string) $donationData->feeCoverAmount);
        }
        if (isset($donationData->tipAmount)) {
            try {
                $donation->setTipAmount((string) $donationData->tipAmount);
            } catch (\UnexpectedValueException $exception) {
                $this->entityManager->rollback();

                return $this->validationError(
                    sprintf("Invalid tipAmount '%s'", $donationData->tipAmount),
                    $exception->getMessage(),
                    false,
                );
            }
        }

        // All calls using the new two-step approach should set all the remaining values in this
        // method every time they `addData()`.
        $donation->setGiftAid($donationData->giftAid);
        $donation->setTipGiftAid($donationData->tipGiftAid ?? $donationData->giftAid);
        $donation->setTbgShouldProcessGiftAid($donation->getCampaign()->getCharity()->isTbgClaimingGiftAid());
        $donation->setDonorHomeAddressLine1($donationData->homeAddress);
        $donation->setDonorHomePostcode($donationData->homePostcode);
        $donation->setDonorFirstName($donationData->firstName);
        $donation->setDonorLastName($donationData->lastName);
        $donation->setDonorEmailAddress($donationData->emailAddress);
        $donation->setTbgComms($donationData->optInTbgEmail);
        $donation->setCharityComms($donationData->optInCharityEmail);
        $donation->setChampionComms($donationData->optInChampionEmail);
        $donation->setDonorBillingAddress($donationData->billingPostalAddress);

        $donation = $this->donationRepository->deriveFees(
            $donation,
            $donationData->cardBrand,
            $donationData->cardCountry
        );

        if ($donation->getPsp() === 'stripe') {
            try {
                $this->updatePaymentIntent($donation);
            } catch (RateLimitException $exception) {
                if ($exception->getStripeCode() !== 'lock_error') {
                    throw $exception; // Only re-try when object level lock failed.
                }

                $this->logger->info(sprintf(
                    'Stripe lock "rate limit" hit while updating payment intent for donation %s – retrying in 1s...',
                    $donation->getId(),
                ));
                sleep(1);

                try {
                    $this->updatePaymentIntent($donation);
                } catch (RateLimitException $exception) {
                    $this->logger->error(sprintf(
                        'Stripe Payment Intent update error from lock "rate limit" on %s, %s [%s]: %s',
                        $donation->getUuid(),
                        get_class($exception),
                        $exception->getStripeCode(),
                        $exception->getMessage(),
                    ));
                    $error = new ActionError(ActionError::SERVER_ERROR, 'Could not update Stripe Payment Intent [C]');
                    $this->entityManager->rollback();

                    return $this->respond(new ActionPayload(500, null, $error));
                }
            } catch (ApiErrorException $exception) {
                $alreadyCapturedMsg = 'The parameter application_fee_amount cannot be updated on a PaymentIntent ' .
                    'after a capture has already been made.';
                if (
                    $exception instanceof InvalidRequestException &&
                    str_starts_with($exception->getMessage(), $alreadyCapturedMsg)
                ) {
                    $latestPI = $this->stripeClient->paymentIntents->retrieve($donation->getTransactionId());
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
                            $latestPI->application_fee_amount,
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

                        return $this->respond(new ActionPayload(500, null, $error));
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

                    return $this->respond(new ActionPayload(500, null, $error));
                }
            }

            if ($donationData->autoConfirmFromCashBalance) {
                $this->stripeClient->paymentIntents->confirm($donation->getTransactionId());
            }
        }

        $this->save($donation);

        return $this->respondWithData($donation->toApiModel());
    }

    private function cancel(Donation $donation): Response
    {
        if ($donation->getDonationStatus() === 'Cancelled') {
            $this->logger->info("Donation ID {$this->args['donationId']} was already Cancelled");
            $this->entityManager->rollback();

            return $this->respondWithData($donation->toApiModel());
        }

        if ($donation->isSuccessful()) {
            // If a donor uses browser back before loading the thank you page, it is possible for them to get
            // a Cancel dialog and send a cancellation attempt to this endpoint after finishing the donation.
            $this->entityManager->rollback();

            return $this->validationError(
                "Donation ID {$this->args['donationId']} could not be cancelled as {$donation->getDonationStatus()}",
                'Donation already finalised'
            );
        }

        $this->logger->info("Donor cancelled ID {$this->args['donationId']}");

        $donation->setDonationStatus('Cancelled');

        // Save & flush early to reduce chance of lock conflicts.
        $this->save($donation);

        if ($donation->getCampaign()->isMatched()) {
            $this->donationRepository->releaseMatchFunds($donation);
        }

        if ($donation->getPsp() === 'stripe') {
            try {
                $this->stripeClient->paymentIntents->cancel($donation->getTransactionId());
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

                    return $this->respond(new ActionPayload(500, null, $error));
                } // Else likely double-send -> fall through to normal 200 OK response and return the donation as-is.
            }
        }

        return $this->respondWithData($donation->toApiModel());
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

        // We log if this fails but don't worry the client about it. We'll just re-try
        // sending the updated status to Salesforce in a future batch sync.
        $this->donationRepository->push($donation, false);
    }

    /**
     * @throws RateLimitException if e.g. Stripe had locked the PI while processing.
     */
    private function updatePaymentIntent(Donation $donation): void
    {
        $this->stripeClient->paymentIntents->update($donation->getTransactionId(), [
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
}
