<?php

namespace MatchBot\Domain;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use Stripe\Mandate;

readonly class MandateService
{
    /** @psalm-suppress PossiblyUnusedMethod - will be used by DI */
    public function __construct(
        private DonationRepository $donationRepository,
        private DonorAccountRepository $donorAccountRepository,
        private CampaignRepository $campaignRepository,
        private EntityManagerInterface $entityManager,
        private DonationService $donationService,
    ) {
    }

    public function makeNextDonationForMandate(RegularGivingMandate $mandate): Donation
    {
        /*
         * @todo: Refuse to create donation with preauth date in future. Other than for the 2nd and 3rd donations in
         *        the mandate its unnecessary. Better to create them only when the date has been reached, so that we'll
         *        be able to confirm immediatly, and have up to date info from the start on the donor's details, whether
         *        or not they cancelled this mandate etc etc.
         */
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

        $this->donationService->enrollNewDonation($donation);
        $mandate->setDonationsCreatedUpTo($donation->getPreAuthorizationDate());

        return $donation;
    }
}
