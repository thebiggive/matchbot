<?php

namespace MatchBot\Application\Messenger\Handler;

use Doctrine\DBAL\Exception\RetryableException;
use MatchBot\Application\Assertion;
use MatchBot\Application\Messenger\DonationStateUpdated;
use MatchBot\Domain\DonationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class DonationStateUpdatedHandler
{
    private const int MAX_PUSH_TRIES = 4;

    public function __construct(
        private DonationRepository $donationRepository,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(DonationStateUpdated $donationStateUpdated)
    {
        $donationUUID = $donationStateUpdated->donationUUID;

        Assertion::uuid($donationUUID, 'Expected donationUUID to be a valid UUID');

        $this->logger->info("DSUH invoked for UUID: $donationUUID");

        $tries = 0;
        do {
            $this->donationRepository->resetIfNecessary();
            $donation = $this->donationRepository->findOneBy(['uuid' => $donationUUID]);

            if ($donation === null) {
                $this->logger->info("DSUH: Null Donation found for UUID: " . $donationUUID);
                throw new \RuntimeException('Donation not found');
            }

            $this->logger->info("DSUH: Real Donation found for UUID: " . $donationUUID);

            try {
                $this->donationRepository->push($donation, $donationStateUpdated->donationIsNew);
                $this->logger->info("DSUH: Donation pushed for UUID: " . $donationUUID);
                return;
            } catch (RetryableException $retryableException) {
                $this->logger->info(
                    "DSUH: RetryableException on attempt to push donation $donationUUID, will retry \n" .
                    $retryableException
                );
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
        } while ($tries++ < self::MAX_PUSH_TRIES);

        $this->logger->error("DSUH: Donation push failed after $tries tries for UUID: " . $donationUUID);
    }
}
