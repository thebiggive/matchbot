<?php

namespace MatchBot\Application\Messenger\Handler;

use MatchBot\Application\Assertion;
use MatchBot\Application\Messenger\DonationCreated;
use MatchBot\Domain\DonationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly class DonationCreatedHandler
{
    public function __construct(
        private DonationRepository $donationRepository,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(DonationCreated $donationCreatedMessage): void
    {
        $donationUUID = $donationCreatedMessage->uuid;

        Assertion::uuid($donationUUID, 'Expected donationUUID to be a valid UUID');

        $this->logger->info("DCH invoked for UUID: $donationUUID");
        try {
            $this->donationRepository->push($donationCreatedMessage, true);
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf(
                "DCH: Exception %s on attempt to push donation %s: %s",
                get_class($exception),
                $donationUUID,
                $exception->getMessage(),
            ));
        }

        $this->logger->info("DCH: Donation pushed for UUID: " . $donationUUID);
    }
}
