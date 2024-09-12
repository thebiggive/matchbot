<?php

namespace MatchBot\Domain;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use Psr\Log\LoggerInterface;
use Stripe\Mandate;

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
