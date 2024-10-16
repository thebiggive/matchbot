<?php

namespace MatchBot\Domain;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use Psr\Log\LoggerInterface;

readonly class MandateService
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
    ) {
    }

    public function setupNewMandate(
        PersonId $donorID,
        Money $amount,
        Campaign $campaign,
        bool $giftAid,
        DayOfMonth $dayOfMonth,
    ): RegularGivingMandate {
        $charityId = Salesforce18Id::ofCharity(
            $campaign->getCharity()->getSalesforceId() ?? throw new \Exception('missing charity SF ID')
        );

        /**
         * For now we assume this exists - @todo-regular-giving ensure that for all accounts (or all accounts that
         * might need it) the account is in the DB with the UUID filled in before this point.
         */
        $donor = $this->donorAccountRepository->findByPersonId($donorID);
        if ($donor === null) {
            throw new \Exception("donor not found with ID {$donorID->id}");
        }

        $mandate = new RegularGivingMandate(
            donorId: $donorID,
            amount: $amount,
            campaignId: Salesforce18Id::ofCampaign(
                $campaign->getSalesforceId() ?? throw new \Exception('missing campaign SF ID')
            ),
            charityId: $charityId,
            giftAid: $giftAid,
            dayOfMonth: $dayOfMonth,
        );

        $this->entityManager->persist($mandate);
        $this->entityManager->flush();

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
            mandateSequenceNumber: DonationSequenceNumber::of(1)
        );
        $this->donationService->enrollNewDonation($firstDonation);
        // @todo-regular-giving - throw if first donation is not fully matched unless donor has said they're OK with
        //                        that.
        // @todo-regular-giving - collect first donation
        // @todo-regular-giving - do same for 2nd and third donations except those are just to be preauthed and enrolled
        //                        and checked for matching, not collected at this point.

        $this->entityManager->flush();

        return $mandate;
    }

    public function makeNextDonationForMandate(RegularGivingMandate $mandate): ?Donation
    {
        $mandateId = $mandate->getId();
        Assertion::notNull($mandateId);

        $lastSequenceNumber = $this->donationRepository->maxSequenceNumberForMandate($mandateId);
        if ($lastSequenceNumber === null) {
            throw new \Exception("No donations found for mandate $mandateId, cannot generate next donation");
        }

        $donor = $this->donorAccountRepository->findByPersonId($mandate->donorId);

        // would only be null if donor was deleted after mandate created.
        Assertion::notNull($donor, "donor not found for id {$mandate->donorId->id}");

        $campaign = $this->campaignRepository->findOneBySalesforceId($mandate->getCampaignId());
        Assertion::notNull($campaign); // we don't delete old campaigns

        $this->entityManager->persist($mandate);
        $this->entityManager->persist($campaign);

        $donation = $mandate->createPreAuthorizedDonation(
            $lastSequenceNumber->next(),
            $donor,
            $campaign,
        );
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

        $this->donationService->createPaymentIntent($donation);
        $mandate->setDonationsCreatedUpTo($preAuthorizationDate);

        return $donation;
    }
}
