<?php

namespace MatchBot\Domain;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use MatchBot\Domain\DomainException\AccountDetailsMismatch;
use MatchBot\Domain\DomainException\CampaignNotOpen;
use MatchBot\Domain\DomainException\NotFullyMatched;
use MatchBot\Domain\DomainException\RegularGivingCollectionEndPassed;
use MatchBot\Domain\DomainException\WrongCampaignType;
use Psr\Log\LoggerInterface;

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
        PersonId $donorID,
        Money $amount,
        Campaign $campaign,
        bool $giftAid,
        DayOfMonth $dayOfMonth,
        ?Country $billingCountry,
        ?string $billingPostCode,
    ): RegularGivingMandate {
        if (! $campaign->isRegularGiving()) {
            throw new WrongCampaignType(
                "Campaign {$campaign->getSalesforceId()} does not accept regular giving"
            );
        }

        $charityId = Salesforce18Id::ofCharity(
            $campaign->getCharity()->getSalesforceId()
        );

        /**
         * For now we assume this exists - @todo-regular-giving ensure that for all accounts (or all accounts that
         * might need it) the account is in the DB with the UUID filled in before this point. Ticket MAT-379
         */
        $donor = $this->donorAccountRepository->findByPersonId($donorID);
        if ($donor === null) {
            throw new \Exception("donor not found with ID {$donorID->id}");
        }

        $donorBillingCountry = $donor->getBillingCountry();
        if ($billingCountry && $donorBillingCountry && !$billingCountry->equals($donorBillingCountry)) {
            throw new AccountDetailsMismatch(
                "Mandate billing country {$billingCountry} does not match donor account country {$donorBillingCountry}"
            );
        }

        $donorBillingPostcode = $donor->getBillingPostcode();

        if (! is_null($billingPostCode) && ! is_null($donorBillingPostcode) && $billingPostCode !== $donorBillingPostcode) {
            throw new AccountDetailsMismatch(
                "Mandate billing postcode {$billingPostCode} does not match donor account postocde {$donorBillingPostcode}"
            );
        }

        if ($billingCountry) {
            $donor->setBillingCountry($billingCountry);
        }

        if (! is_null($billingPostCode)) {
            $donor->setBillingPostcode($billingPostCode);
        }

        $donor->assertHasRequiredInfoForRegularGiving();

        $mandate = new RegularGivingMandate(
            donorId: $donorID,
            donationAmount: $amount,
            campaignId: Salesforce18Id::ofCampaign($campaign->getSalesforceId()),
            charityId: $charityId,
            giftAid: $giftAid,
            dayOfMonth: $dayOfMonth,
        );

        $this->entityManager->persist($mandate);

        $firstDonation = new Donation(
            amount: $amount->toNumericString(),
            currencyCode: $amount->currency->isoCode(),
            paymentMethodType: PaymentMethodType::Card,
            campaign: $campaign,
            charityComms: false,
            championComms: false,
            pspCustomerId: $donor->stripeCustomerId->stripeCustomerId,
            optInTbgEmail: false,
            donorName: $donor->donorName,
            emailAddress: $donor->emailAddress,
            countryCode: $donor->getBillingCountryCode(),
            tipAmount: '0',
            mandate: $mandate,
            mandateSequenceNumber: DonationSequenceNumber::of(1),
            billingPostcode: $donorBillingPostcode,
        );

        $secondDonation = $this->createFutureDonationInAdvanceOfActivation($mandate, 2, $donor, $campaign);
        $thirdDonation = $this->createFutureDonationInAdvanceOfActivation($mandate, 3, $donor, $campaign);

        /** @var Donation[] $donations */
        $donations = [$firstDonation, $secondDonation, $thirdDonation];

        try {
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
        } catch (\Throwable $e) {
            foreach ($donations as $donation) {
                $this->donationService->cancel($donation);
                $mandate->cancel();
            }
            $this->entityManager->flush();
            throw $e;
        }

        // @todo-regular-giving - collect first donation (currently created as pending, not collected)

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
}
