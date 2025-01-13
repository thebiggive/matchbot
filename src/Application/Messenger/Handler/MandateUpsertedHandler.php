<?php

namespace MatchBot\Application\Messenger\Handler;

use MatchBot\Application\Assertion;
use MatchBot\Application\Messenger\AbstractStateChanged;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Application\Messenger\MandateUpserted;
use MatchBot\Domain\DoctrineDonationRepository;
use MatchBot\Domain\DonationRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sends donations to Salesforce.
 */
#[AsMessageHandler]
readonly class MandateUpsertedHandler
{
    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(MandateUpserted $message): void
    {
        $this->logger->info("DUH invoked for UUID: $message->uuid");
    }
}
