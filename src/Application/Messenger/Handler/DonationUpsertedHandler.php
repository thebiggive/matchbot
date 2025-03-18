<?php

namespace MatchBot\Application\Messenger\Handler;

use MatchBot\Application\Assertion;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Domain\DonationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sends donations to Salesforce.
 */
#[AsMessageHandler]
readonly class DonationUpsertedHandler
{
    public function __construct(
        private DonationRepository $donationRepository,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(DonationUpserted $message): void
    {
        $donationUUID = $message->uuid;

        Assertion::uuid($donationUUID, 'Expected donationUUID to be a valid UUID');

        $jsonSnapshot = $message->jsonSnapshot;

        $messageDate = (string)($jsonSnapshot[DonationUpserted::SNAPSHOT_TAKEN_AT] ?? 'unknown date');

        $this->logger->info("DUH invoked for UUID: $donationUUID, handling message from $messageDate");

        try {
            $this->donationRepository->push($message);
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf(
                "DUH: Exception %s on attempt to push donation %s: %s with message from %s. Trace: %s",
                get_class($exception),
                $donationUUID,
                $exception->getMessage(),
                $messageDate,
                $exception->getTraceAsString(),
            ));
        }

        $this->logger->info("DUH: Donation (maybe) pushed for UUID: " . $donationUUID);
    }
}
