<?php

namespace MatchBot\Domain;

use Assert\AssertionFailedException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\ClientException;
use MatchBot\Application\Assertion;
use MatchBot\Client\Stripe;
use MatchBot\Domain\DomainException\AccountDetailsMismatch;
use MatchBot\Domain\DomainException\BadCommandException;
use MatchBot\Domain\DomainException\CampaignNotOpen;
use MatchBot\Domain\DomainException\CouldNotCancelStripePaymentIntent;
use MatchBot\Domain\DomainException\DonationNotCollected;
use MatchBot\Domain\DomainException\HomeAddressRequired;
use MatchBot\Domain\DomainException\MandateAlreadyExists;
use MatchBot\Domain\DomainException\NonCancellableStatus;
use MatchBot\Domain\DomainException\NotFullyMatched;
use MatchBot\Domain\DomainException\PaymentIntentNotSucceeded;
use MatchBot\Domain\DomainException\RegularGivingCollectionEndPassed;
use MatchBot\Domain\DomainException\WrongCampaignType;
use Psr\Log\LoggerInterface;
use Stripe\ConfirmationToken;
use Stripe\Exception\ApiErrorException as StripeApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Symfony\Component\Messenger\Envelope;
use UnexpectedValueException;

readonly class RegularGivingService
{
    public function __construct(
        private \DateTimeImmutable $now,
        private DonationRepository $donationRepository,
        private DonorAccountRepository $donorAccountRepository,
        private CampaignRepository $campaignRepository,
        private EntityManagerInterface $entityManager,
        private DonationService $donationService,
        private LoggerInterface $log,
        private RegularGivingMandateRepository $regularGivingMandateRepository,
        private RegularGivingNotifier $regularGivingNotifier,
        private Stripe $stripe,
    ) {
    }

    /**
     * Creates but does not yet activate a Mandate. Stripe first charge succeeded webhook is responsible for activation.
     *
     * @param bool|null $homeIsOutsideUk
     * @param bool $matchDonations
     * @param PostCode|null $homePostcode
     * @param string|null $homeAddress
     * @param bool $charityComms
     * @param bool $tbgComms
     * @param Country|null $billingCountry
     * @param string|null $billingPostCode
     * @throws CampaignNotOpen
     * @throws DomainException\CharityAccountLacksNeededCapaiblities
     * @throws DomainException\CouldNotMakeStripePaymentIntent
     * @throws DomainException\StripeAccountIdNotSetForAccount
     * @throws WrongCampaignType
     * @throws NotFullyMatched
     * @throws \Doctrine\DBAL\Exception\ServerException
     * @throws \Doctrine\ORM\Exception\ORMException
     * @throws \MatchBot\Client\NotFoundException
     * @throws \Symfony\Component\Notifier\Exception\TransportExceptionInterface
     * @throws StripeApiErrorException
     * @throws DonationNotCollected
     * @throws PaymentIntentNotSucceeded
     * @throws CampaignNotOpen
     * @throws AccountDetailsMismatch
     * @throws CouldNotCancelStripePaymentIntent
     * @throws UnexpectedValueException if the amount is out of the allowed range
     */
    public function setupNewMandate(
        DonorAccount $donor,
        Money $amount,
        Campaign $campaign,
        bool $giftAid,
        DayOfMonth $dayOfMonth,
        ?Country $billingCountry,
        ?string $billingPostCode,
        bool $tbgComms,
        bool $charityComms,
        ?StripeConfirmationTokenId $confirmationTokenId,
        /**
         * Used for gift aid claim, must be set if gift aid is true. Will be saved on to the donor account.
         */
        ?string $homeAddress,
        /**
         * Used for gift aid claim but optional as not given if donor is outside UK. Will be saved to donor account.
         */
        ?PostCode $homePostcode,
        bool $matchDonations,
        ?bool $homeIsOutsideUk,
    ): RegularGivingMandate {
        // should save the address to the donor account if an address was given.

        $this->ensureCampaignAllowsRegularGiving($campaign);
        $this->ensureBillingCountryMatchesDonorBillingCountry($donor, $billingCountry);
        $this->ensureBillingPostcodeMatchesDonorBillingPostcode($donor, $billingPostCode);
        $this->ensureCampaignIsOpen($campaign);
        $this->cancelAnyPendingMandateForDonorAndCampaign($donor, $campaign);

        if ($billingCountry) {
            $donor->setBillingCountry($billingCountry);
        }

        if (is_string($billingPostCode)) {
            $donor->setBillingPostcode($billingPostCode);
        }

        $donor->assertHasRequiredInfoForRegularGiving();

        $mandate = new RegularGivingMandate(
            donorId: $donor->id(),
            donationAmount: $amount,
            campaignId: Salesforce18Id::ofCampaign($campaign->getSalesforceId()),
            charityId: $campaign->getCharityId(),
            giftAid: $giftAid,
            dayOfMonth: $dayOfMonth,
            tbgComms: $tbgComms,
            charityComms: $charityComms,
            matchDonations: $matchDonations
        );

        $donorPreviousHomeAddress = $donor->getHomeAddressLine1();
        $donorPreviousHomePostcode = $donor->getHomePostcode();

        $homeAddressSupplied = is_string($homeAddress) && trim($homeAddress) !== '';
        if ($homeAddressSupplied) {
            $donor->setHomeAddressLine1(trim($homeAddress));
            $donor->setHomePostcode($homePostcode);

            Assertion::notNull($homeIsOutsideUk);
            $donor->setHomeIsOutsideUK($homeIsOutsideUk);
        }

        if ($giftAid && ! $homeAddressSupplied) {
            throw new HomeAddressRequired('Home Address is required when gift aid is selected');
        }

        /**
         * We create exactly three donations because that is what we offer to match. The first donation is special
         * because we will collect it immediately. The remaining donations will be saved in the database to collect
         * in the following months.
         *
         */
        $donations = [
            $firstDonation = $mandate->createPendingFirstDonation($campaign, $donor),
            $this->createFutureDonationInAdvanceOfActivation($mandate, 2, $donor, $campaign),
            $this->createFutureDonationInAdvanceOfActivation($mandate, 3, $donor, $campaign)
        ];

        $this->entityManager->persist($mandate);

        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Entity Manager is now closed so there's nothing we can do except throw back to UI.
            // Should rarely happen as UI can be designed to stop people getting to this point.
            if (str_contains($e->getMessage(), 'RegularGivingMandate.person_id_if_active')) {
                throw new MandateAlreadyExists(
                    'You already have an active or pending regular giving mandate for ' . $campaign->getCampaignName()
                );
            }
            throw $e;
        }

        try {
            $this->enrollAndMatchDonations($donations, $mandate);
        } catch (\Throwable $e) {
            $donor->setHomeAddressLine1($donorPreviousHomeAddress);
            $donor->setHomePostcode(
                is_string($donorPreviousHomePostcode) ?
                    PostCode::of($donorPreviousHomePostcode, true) : null
            );

            $mandate->cancel($e->getMessage(), new \DateTimeImmutable(), MandateCancellationType::EnrollingDonationFailed);
            foreach ($donations as $donation) {
                $this->donationService->cancel($donation);
            }
            $this->entityManager->flush();

            throw $e;
        }

        $donorsSavedPaymentMethod = $donor->getRegularGivingPaymentMethod();
        Assertion::true(
            ($confirmationTokenId && !$donorsSavedPaymentMethod) ||
            (! $confirmationTokenId && $donorsSavedPaymentMethod),
            'Confirmation token must be given iff there is no payment method on file'
        );

        try {
            if ($confirmationTokenId) {
                // The client should be setting every RG card to off_session so we don't need to compare the token's
                // `setup_future_usage` for the 3rd arg here.
                $this->donationService->confirmOnSessionDonation($firstDonation, $confirmationTokenId, ConfirmationToken::SETUP_FUTURE_USAGE_OFF_SESSION);
            } else {
                \assert($donorsSavedPaymentMethod !== null);
                $this->donationService->confirmDonationWithSavedPaymentMethod($firstDonation, $donorsSavedPaymentMethod);
            }
        } catch (PaymentIntentNotSucceeded $e) {
            $this->entityManager->flush();
            $e->mandate = $mandate;
            if ($e->paymentIntent->status === PaymentIntent::STATUS_REQUIRES_ACTION) {
                throw $e;
            }
        }

        $this->donationService->queryStripeToUpdateDonationStatus($firstDonation);

        if (!$firstDonation->getDonationStatus()->isSuccessful()) {
            $this->cancelNewMandate($mandate, $firstDonation, $donor, $donorPreviousHomeAddress, $donorPreviousHomePostcode);

            throw new DonationNotCollected(
                'First Donation in Regular Giving mandate could not be collected, not activating mandate'
            );
        }

        return $mandate;
    }

    /**
     * @param RegularGivingMandate $mandate A Regular Giving Mandate. Must have status 'active'.
     *
     * @throws AssertionFailedException
     * @throws CampaignNotOpen
     * @throws RegularGivingCollectionEndPassed
     * @throws WrongCampaignType
     *
     * @return ?Donation A new donation, or null if either the regular giving collection end date has passed or we
     * need to wait more before creating another donation.
     */
    public function makeNextDonationForMandate(RegularGivingMandate $mandate): ?Donation
    {
        $mandateId = $mandate->getId();
        Assertion::notNull($mandateId);

        // safe to assert as we assume any caller to this will have selected an active mandate from the DB.
        Assertion::same(MandateStatus::Active, $mandate->getStatus());

        $lastSequenceNumber = $this->donationRepository->maxSequenceNumberForMandate($mandateId);
        if ($lastSequenceNumber === null) {
            throw new \Exception("No donations found for mandate $mandateId, cannot generate next donation");
        }

        $donor = $this->donorAccountRepository->findByPersonId($mandate->donorId());

        // would only be null if donor was deleted after mandate created.
        Assertion::notNull($donor, "donor not found for id {$mandate->donorId()->id}");

        $donor->assertHasRequiredInfoForRegularGiving();

        $campaign = $this->campaignRepository->findOneBySalesforceId($mandate->getCampaignId());
        Assertion::notNull($campaign); // we don't delete old campaigns

        $this->entityManager->persist($mandate);
        $this->entityManager->persist($campaign);

        try {
            $donation = $mandate->createPreAuthorizedDonation(
                $lastSequenceNumber->next(),
                $donor,
                $campaign,
            );
        } catch (RegularGivingCollectionEndPassed $e) {
            $mandate->campaignEnded();
            $this->log->info($e->getMessage());
            $this->entityManager->flush();
            return null;
        }

        $campaign->checkIsReadyToAcceptDonation($donation, $this->now);

        $preAuthorizationDate = $donation->getPreAuthorizationDate();
        \assert($preAuthorizationDate instanceof \DateTimeImmutable);

        if ($preAuthorizationDate > $this->now) {
            $this->log->info(
                "Mandate #{$mandateId}: Not creating donation yet as will only be authorized to pay on " .
                $preAuthorizationDate->format("Y-m-d") . ' and now is ' . $this->now->format("Y-m-d")
            );

            // Throw this donation away without persisting, we can create it again when the authorization date is
            // reached.
            return null;
        }

        $mandate->setDonationsCreatedUpTo($preAuthorizationDate);

        return $donation;
    }

    /**
     * @return list<array<array-key, mixed>> List of mandates as front end api models
     */
    public function allMandatesForDisplayToDonor(PersonId $donor): array
    {
        $mandatesWithCharities = $this->regularGivingMandateRepository->allMandatesForDisplayToDonor($donor);

        $currentUKTime = $this->now->setTimezone(new \DateTimeZone("Europe/London"));

        return array_map(/**
         * @param array{0: RegularGivingMandate, 1: Charity} $tuple
         * @return array
         */            static fn(array $tuple) => $tuple[0]->toFrontEndApiModel($tuple[1], $currentUKTime),
            $mandatesWithCharities
        );
    }

    public function createFutureDonationInAdvanceOfActivation(RegularGivingMandate $mandate, int $number, DonorAccount $donor, Campaign $campaign): Donation
    {
        return $mandate->createPreAuthorizedDonation(
            DonationSequenceNumber::of($number),
            $donor,
            $campaign,
            requireActiveMandate: false,
            expectedActivationDate: $this->now
        );
    }

    private function ensureCampaignAllowsRegularGiving(Campaign $campaign): void
    {
        if (!$campaign->isRegularGiving()) {
            throw new WrongCampaignType(
                "Campaign {$campaign->getSalesforceId()} does not accept regular giving"
            );
        }
    }

    /**
     * Either one may be null, but if both are non-null then they must be equal. We do not support changing
     * donor billing country here.
     */
    private function ensureBillingCountryMatchesDonorBillingCountry(DonorAccount $donor, ?Country $billingCountry): void
    {
        $donorBillingCountry = $donor->getBillingCountry();
        if ($billingCountry && $donorBillingCountry && !$billingCountry->equals($donorBillingCountry)) {
            throw new AccountDetailsMismatch(
                "Mandate billing country {$billingCountry} does not match donor account country {$donorBillingCountry}"
            );
        }
    }

    private function ensureBillingPostcodeMatchesDonorBillingPostcode(DonorAccount $donor, ?string $billingPostCode): void
    {
        $donorBillingPostcode = $donor->getBillingPostcode();

        if (!is_null($billingPostCode) && !is_null($donorBillingPostcode) && $billingPostCode !== $donorBillingPostcode) {
            throw new AccountDetailsMismatch(
                "Mandate billing postcode {$billingPostCode} does not match donor account postcode {$donorBillingPostcode}"
            );
        }
    }

    /**
     * @param list<Donation> $donations
     */
    private function enrollAndMatchDonations(array $donations, RegularGivingMandate $mandate): void
    {
        foreach ($donations as $donation) {
            // dispatchUpdateMessage: false because donations will be sent after mandate has SF ID by
            // MandateUpsertedHandler. Also not dispatching because sending the 1st pending donation seems to be causing
            // alarms in Regtest where SF thinks matchbot is asking it to convert a donation from Collected to Pending,
            // which is not allowed.
            $this->donationService->enrollNewDonation(
                donation: $donation,
                attemptMatching: $mandate->isMatched(),
                dispatchUpdateMessage: false,
            );
            if (!$donation->isFullyMatched() && $mandate->isMatched()) {
                /** @psalm-suppress RedundantCondition - not redundant for PHPStan */
                \assert($donations !== []);
                $maxMatchable = RegularGivingMandate::averageMatched($donations);

                throw new NotFullyMatched(
                    "Donation could not be fully matched, need to match {$donation->getAmount()}," .
                    " only matched {$donation->getFundingWithdrawalTotal()}",
                    $maxMatchable
                );
            }

            Assertion::same(
                $donation->getFundingWithdrawalTotal(),
                $mandate->getMatchedAmount()->toNumericString()
            );
        }
    }

    /**
     * Cancels a mandate when the donor has decided they want to stop making donations.
     *
     * @param RegularGivingMandate $mandate - must have been persisted, i.e. have an ID set.
     * @throws NonCancellableStatus
     * @throws CouldNotCancelStripePaymentIntent
     */
    public function cancelMandate(
        RegularGivingMandate $mandate,
        string $reason,
        MandateCancellationType $cancellationType,
    ): void {
        $mandate->cancel(reason: $reason, at: $this->now, type: $cancellationType);

        $cancellableDonations = $this->donationRepository->findPendingAndPreAuthedForMandate($mandate->getUuid());

        Assertion::maxCount(
            $cancellableDonations,
            3,
            "Too many donations found to cancel for mandate {$mandate->getUuid()}, should be max 3}"
        );

        foreach ($cancellableDonations as $donation) {
            $this->donationService->cancel($donation);
        }

        $this->entityManager->flush();
    }

    private function activateMandateNotifyDonor(
        Donation $firstDonation,
        RegularGivingMandate $mandate,
        DonorAccount $donor,
        Campaign $campaign,
        StripePaymentMethodId $paymentMethodId
    ): void {
        $donor->setRegularGivingPaymentMethod($paymentMethodId);
        $mandate->activate($this->now);

        $this->entityManager->flush();

        try {
            $this->regularGivingNotifier->notifyNewMandateCreated($mandate, $donor, $campaign, $firstDonation);
        } catch (ClientException $exception) {
            $this->log->error(
                "Could not send notification for mandate #{$mandate->getId()}: " . $exception->__toString()
            );
        }
    }

    public function cancelNewMandate(
        RegularGivingMandate $mandate,
        Donation $firstDonation,
        DonorAccount $donor,
        ?string $donorPreviousHomeAddress,
        ?string $donorPreviousHomePostcode
    ): void {
        $mandate->cancel(
            reason: "Donation failed, status is {$firstDonation->getDonationStatus()->name}",
            at: new \DateTimeImmutable(),
            type: MandateCancellationType::FirstDonationUnsuccessful
        );

        $donor->setHomeAddressLine1($donorPreviousHomeAddress);
        $donor->setHomePostcode(
            is_string($donorPreviousHomePostcode) ?
                PostCode::of($donorPreviousHomePostcode, true) : null
        );

        $this->entityManager->flush();
    }

    /**
     * Activates any previously created Mandate via {@see self::activateMandateNotifyDonor()} assuming
     * pre-conditions hold. Returns as a no-op if called for a non-regular giving donation.
     */
    public function updatePossibleMandateFromSuccessfulCharge(
        Donation $donation,
        StripePaymentMethodId $paymentMethodId
    ): void {
        \assert($donation->getDonationStatus()->isSuccessful());

        $mandate = $donation->getMandate();
        if ($mandate === null) {
            return;
        }

        $mandateSequenceNumber = $donation->getMandateSequenceNumber();
        \assert($mandateSequenceNumber !== null);

        if ($mandateSequenceNumber->number !== 1) {
            // only want to update the mandate based on its initial donation being successfully paid.
            return;
        }

        // We explicitly DO NOT check for the campaign being closed at this point. That was already checked
        // when the mandate was created, we don't want to make things inconsistent by blocking the payment
        // now. If it closed more than a few minutes ago then the mandate and payment intent would have been
        // cancelled already by the `MatchBot\Application\Commands\ExpirePendingMandates` command.

        $donor = $this->donorAccountRepository->findByPersonId($mandate->donorId());
        \assert($donor !== null);


        try {
            $this->activateMandateNotifyDonor(
                firstDonation: $donation,
                mandate: $mandate,
                donor: $donor,
                campaign: $donation->getCampaign(),
                paymentMethodId: $paymentMethodId,
            );
        } catch (\MatchBot\Application\AssertionFailedException $e) {
            $this->log->warning("Could not activate regular giving mandate {$mandate->getId()}: {$e->getMessage()}");
        }
    }

    /**
     * @throws CampaignNotOpen
     */
    private function ensureCampaignIsOpen(Campaign $campaign): void
    {
        if (! $campaign->isOpenForFinalising($this->now)) {
            throw new CampaignNotOpen();
        }
    }

    /**
     * A donor cannot have two active or pending mandates for the same campaign, so if they ask to create a new
     * one when there is already one pending we cancel the pending one(s).
     */
    private function cancelAnyPendingMandateForDonorAndCampaign(DonorAccount $donor, Campaign $campaign): void
    {
        $mandatesToCancel = $this->regularGivingMandateRepository->allPendingForDonorAndCampaign(
            $donor->id(),
            $campaign->getSalesforceId(),
        );

        foreach ($mandatesToCancel as $mandate) {
            $this->cancelMandate(
                $mandate,
                'Cancelled to make way for new mandate',
                MandateCancellationType::ReplacedByNewMandate,
            );
        }
    }

    /**
     * Removes any existing regular giving payment method for a donor, and replaces it with a new one.
     *
     * @param StripePaymentMethodId $methodId must be a payment method attached to the given donor's stripe  customer account.
     * @throws InvalidRequestException
     */
    public function changeDonorRegularGivingPaymentMethod(DonorAccount $donor, StripePaymentMethodId $methodId): PaymentMethod
    {
        $newPaymentMethod = $this->stripe->retrievePaymentMethod($donor->stripeCustomerId, $methodId);
        $previousPaymentMethodId = $donor->getRegularGivingPaymentMethod();
        $donor->setRegularGivingPaymentMethod($methodId);

        if ($previousPaymentMethodId) {
            $this->stripe->detatchPaymentMethod($previousPaymentMethodId);
        }

        $this->entityManager->flush();

        return $newPaymentMethod;
    }

    /**
     * @throws BadCommandException
     */
    public function removeDonorRegularGivingPaymentMethod(DonorAccount $donor): void
    {
        $this->checkDonorHasNoActiveMandates($donor);

        $paymentMethodId = $donor->getRegularGivingPaymentMethod();
        if ($paymentMethodId === null) {
            // our work here is done.
            return;
        }

        $this->stripe->detatchPaymentMethod($paymentMethodId);
        $donor->removeRegularGivingPaymentMethod();

        $this->entityManager->flush();
    }

    /**
     * @throws BadCommandException
     */
    private function checkDonorHasNoActiveMandates(DonorAccount $donor): void
    {
        $activeMandates = $this->regularGivingMandateRepository->allActiveMandatesForDonor($donor->id());

        if ($activeMandates !== []) {
            $count = count($activeMandates);
            if ($count === 1) {
                $charity = $activeMandates[0][1];

                $message = "You have an active regular giving mandate for {$charity->getName()}. 
                If you wish to remove your payment method please first cancel this mandate.";
            } else {
                $message = "You have $count active regular giving mandates. 
                If you wish to remove your payment method please first cancel these mandates";
            }

            throw new BadCommandException($message);
        }
    }
}
