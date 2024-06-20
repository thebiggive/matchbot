<?php

namespace MatchBot\Application\Messenger\Handler;

use MatchBot\Application\Assertion;
use MatchBot\Application\Messenger\DonationStateUpdated;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
use MatchBot\Domain\DonationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class DonationStateUpdatedHandler
{
    public function __construct(
        private DonationRepository $donationRepository,
        private RetrySafeEntityManager $em,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(DonationStateUpdated $donationStateUpdated)
    {
        $donationUUID = $donationStateUpdated->donationUUID;

        Assertion::uuid($donationUUID, 'Expected donationUUID to be a valid UUID');

        $this->logger->info("DSUH invoked for UUID: $donationUUID");
        if (! $this->em->isOpen()) {
            // We assume this same EM is in use by the donation repository, so needs resetting to allow that to work.
            $this->em->resetManager();
        }

        $donation = $this->donationRepository->findOneBy(['uuid' => $donationUUID]);

        if ($donation === null) {
            $this->logger->info("DSUH: Null Donation found for UUID: " . $donationUUID);
            throw new \RuntimeException('Donation not found');
        }

        $this->logger->info("DSUH: Real Donation found for UUID: " . $donationUUID);

        try {
            $this->donationRepository->push($donation, $donationStateUpdated->donationIsNew);
        } catch (\Throwable $exception) {
            // getId() works on proxy object, does not trigger lazy loading
            $campaginID = $donation->getCampaign()->getId();
            $this->logger->error(
                "DSUH: Exception on attempt to push donation $donationUUID, for campaign # $campaginID \n" .
                "will re-throw \n" .
                $exception
            );
            throw $exception;
        }
    }
}
