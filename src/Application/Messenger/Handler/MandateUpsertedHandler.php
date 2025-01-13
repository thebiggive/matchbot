<?php

namespace MatchBot\Application\Messenger\Handler;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use MatchBot\Application\Messenger\AbstractStateChanged;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Application\Messenger\MandateUpserted;
use MatchBot\Client\Mandate;
use MatchBot\Domain\DoctrineDonationRepository;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\Salesforce18Id;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
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
        private LoggerInterface $logger,
        private Mandate $client,
        private EntityManagerInterface $entityManager,
        private Clock $clock,
    ) {
    }

    public function __invoke(MandateUpserted $message): void
    {
        $this->logger->info("MUH invoked for UUID: $message->uuid");
        $sfId = $this->client->createOrUpdate($message);
        $this->setSalesforceFields($message->uuid, $sfId);
    }

    /**
     * @todo-regular-giving DRY up duplication with \MatchBot\Domain\DoctrineDonationRepository::setSalesforceFields
     */
    private function setSalesforceFields(string $uuid, ?Salesforce18Id $salesforceId): void
    {
        $query = $this->entityManager->createQuery(
            <<<'DQL'
            UPDATE Matchbot\Domain\RegularGivingMandate mandate
            SET
                mandate.salesforceId = :salesforceId,
                mandate.salesforcePushStatus = 'complete',
                mandate.salesforceLastPush = :now
            WHERE mandate.uuid = :uuid
            DQL
        );

        $query->setParameter('now', $this->clock->now());
        $query->setParameter('salesforceId', $salesforceId?->value);
        $query->setParameter('uuid', $uuid);
        $query->execute();
    }
}
