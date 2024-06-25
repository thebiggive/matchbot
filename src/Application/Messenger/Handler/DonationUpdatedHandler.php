<?php

namespace MatchBot\Application\Messenger\Handler;

use MatchBot\Application\Assertion;
use MatchBot\Application\Messenger\DonationUpdated;
use MatchBot\Domain\DonationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class DonationUpdatedHandler
{
    public function __construct(
        private DonationRepository $donationRepository,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(DonationUpdated $donationUpdatedMessage): void
    {
        $donationUUID = $donationUpdatedMessage->uuid;

        Assertion::uuid($donationUUID, 'Expected donationUUID to be a valid UUID');

        $this->logger->info("DUH invoked for UUID: $donationUUID");
        try {
            $this->donationRepository->push($donationUpdatedMessage, false);
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf(
                "DUH: Exception %s on attempt to push donation %s: %s",
                get_class($exception),
                $donationUUID,
                $exception->getMessage(),
            ));
        }

        $this->logger->info("DUH: Donation pushed for UUID: " . $donationUUID);
    }
}
