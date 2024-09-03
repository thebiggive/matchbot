<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;
use Stripe\Mandate;

readonly class MandateService
{
    /** @psalm-suppress PossiblyUnusedMethod - will be used by DI */
    public function __construct(
        private DonationRepository $donationRepository,
        private DonorAccountRepository $donorAccountRepository,
        private CampaignRepository $campaignRepository,
    ) {
    }
    public function makeNextDonationForMandate(RegularGivingMandate $mandate): Donation
    {
        $mandateId = $mandate->getId();
        Assertion::notNull($mandateId);

        $lastSequenceNumber = $this->donationRepository->maxSequenceNumberForMandate($mandateId);
        if ($lastSequenceNumber === null) {
            throw new \Exception("No donations found for mandate, cannot generate next donation");
        }

        $donor = $this->donorAccountRepository->findByPersonId($mandate->donorId);
        Assertion::notNull($donor); // would only be null if donor was deleted after mandate created.

        $campaign = $this->campaignRepository->findOneBySalesforceId($mandate->getCampaignId());

        $donation = $mandate->createPreAuthorizedDonation(
            $lastSequenceNumber->next(),
            $donor,
            $campaign,
        );

        return $donation;
    }
}
