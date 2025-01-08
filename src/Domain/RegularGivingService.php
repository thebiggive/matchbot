<?php

namespace MatchBot\Domain;

use Assert\AssertionFailedException;
use Doctrine\DBAL\Exception\ServerException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\ORMException;
use MatchBot\Application\Assertion;
use MatchBot\Client\NotFoundException;
use MatchBot\Client\Stripe;
use MatchBot\Domain\DomainException\AccountDetailsMismatch;
use MatchBot\Domain\DomainException\CampaignNotOpen;
use MatchBot\Domain\DomainException\CharityAccountLacksNeededCapaiblities;
use MatchBot\Domain\DomainException\CouldNotMakeStripePaymentIntent;
use MatchBot\Domain\DomainException\NotFullyMatched;
use MatchBot\Domain\DomainException\RegularGivingCollectionEndPassed;
use MatchBot\Domain\DomainException\StripeAccountIdNotSetForAccount;
use MatchBot\Domain\DomainException\WrongCampaignType;
use Psr\Log\LoggerInterface;
use Symfony\Component\Notifier\Exception\TransportExceptionInterface;

readonly class RegularGivingService
{
    /** @psalm-suppress PossiblyUnusedMethod - will be used by DI */
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
     * @param string|null $billingPostCode
     * @param Country|null $billingCountry
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
     */
    public function setupNewMandate(
        DonorAccount $donor,
        Money $amount,
        Campaign $campaign,
        bool $giftAid,
        DayOfMonth $dayOfMonth,
        ?Country $billingCountry,
        ?string $billingPostCode,
        ?StripeConfirmationTokenId $confirmationTokenId = null,
    ): RegularGivingMandate {
        $this->ensureCampaignAllowsRegularGiving($campaign);
        $this->ensureBillingCountryMatchesDonorBillingCountry($donor, $billingCountry);
        $this->ensureBillingPostcodeMatchesDonorBillingPostcode($donor, $billingPostCode);

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
        );

        /**
         * We create exactly three donations because that is what we offer to match. The first donation is special
         * because we will collect it immediately. The remaining donations will be saved in the database to collect
         * in the following months.
         *
         */
        $donations = [
            $firstDonation = $mandate->createPendingFirstDonation($amount, $campaign, $donor),
            $this->createFutureDonationInAdvanceOfActivation($mandate, 2, $donor, $campaign),
            $this->createFutureDonationInAdvanceOfActivation($mandate, 3, $donor, $campaign)
        ];

        $this->entityManager->persist($mandate);

        try {
            $this->enrollAndMatchDonations($donations, $mandate);
        } catch (\Throwable $e) {
            foreach ($donations as $donation) {
                $this->donationService->cancel($donation);
                $mandate->cancel();
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

        if ($confirmationTokenId) {
            $methodId = $this->confirmWithNewPaymentMethod($firstDonation, $confirmationTokenId);
            $donor->setRegularGivingPaymentMethod($methodId);
        } else {
            \assert($donorsSavedPaymentMethod !== null);
            // @todo-regular-giving - consider if we need to switch to sync confirmation that doesn't rely on a callback
            // hook or something so we can avoid activating the mandate if the first donation is not collected.
            $this->donationService->confirmDonationWithSavedPaymentMethod($firstDonation, $donorsSavedPaymentMethod);
        }

        $mandate->activate($this->now);

        $this->entityManager->flush();

        $this->regularGivingNotifier->notifyNewMandateCreated($mandate, $donor, $campaign, $firstDonation);

        return $mandate;
    }

    /**
     * @param RegularGivingMandate $mandate A Regular Giving Mandate. Must have status 'active'.
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

        $donor = $this->donorAccountRepository->findByPersonId($mandate->donorId);

        // would only be null if donor was deleted after mandate created.
        Assertion::notNull($donor, "donor not found for id {$mandate->donorId->id}");

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
            return null;
        }
        $preAuthorizationDate = $donation->getPreAuthorizationDate();
        \assert($preAuthorizationDate instanceof \DateTimeImmutable);

        if ($preAuthorizationDate > $this->now) {
            $this->log->info(
                "Not creating donation yet as will only be authorized to pay on " .
                $preAuthorizationDate->format("Y-m-d") . ' and now is ' . $this->now->format("Y-m-d")
            );

            // Throw this donation away without persisting, we can create it again when the authorization date is
            // reached.
            return null;
        }

        $mandate->setDonationsCreatedUpTo($preAuthorizationDate);

        return $donation;
    }

    public function allActiveForDonorAsApiModel(PersonId $donor): array
    {
        $mandatesWithCharities = $this->regularGivingMandateRepository->allActiveForDonorWithCharities($donor);

        $currentUKTime = $this->now->setTimezone(new \DateTimeZone("Europe/London"));

        return array_map(/**
         * @param array{0: RegularGivingMandate, 1: Charity} $tuple
         * @return array
         */            static fn(array $tuple) => $tuple[0]->toFrontendApiModel($tuple[1], $currentUKTime),
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
                "Mandate billing postcode {$billingPostCode} does not match donor account postocde {$donorBillingPostcode}"
            );
        }
    }

    /**
     * @param list<Donation> $donations
     */
    private function enrollAndMatchDonations(array $donations, RegularGivingMandate $mandate): void
    {
        foreach ($donations as $donation) {
            $this->donationService->enrollNewDonation($donation);
            if (!$donation->isFullyMatched()) {
                // @todo-regular-giving:
                // see ticket DON-1003 - that will require us to not throw here if the donor doesn't mind their donations being unmatched.
                throw new NotFullyMatched(
                    "Donation could not be fully matched, need to match {$donation->getAmount()}," .
                    " only matched {$donation->getFundingWithdrawalTotal()}"
                );
            }

            Assertion::same(
                $donation->getFundingWithdrawalTotal(),
                $mandate->getMatchedAmount()->toNumericString()
            );
        }
    }

    private function confirmWithNewPaymentMethod(Donation $firstDonation, StripeConfirmationTokenId $confirmationTokenId): StripePaymentMethodId
    {
        $intent = $this->donationService->confirmOnSessionDonation($firstDonation, $confirmationTokenId);
        $chargeId = $intent->latest_charge;
        if ($chargeId === null) {
            // AFAIK there should always be charge ID attached to the intent at this point
            throw new \Exception('No charge ID on payment intent after confirming regular giving donation');
        }

        $charge = $this->stripe->retrieveCharge((string)$chargeId);
        $paymentMethodId = $charge->payment_method;
        if ($paymentMethodId === null) {
            // AFAIK there should always be payment method ID attached to the charge at this point.
            throw new \Exception('No payment method ID on charge after confirming regular giving donation');
        }

        return StripePaymentMethodId::of($paymentMethodId);
    }
}
