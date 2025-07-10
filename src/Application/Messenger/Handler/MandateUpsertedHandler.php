<?php

namespace MatchBot\Application\Messenger\Handler;

use Assert\Assertion;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Application\Messenger\MandateUpserted;
use MatchBot\Client\BadRequestException;
use MatchBot\Client\BadResponseException;
use MatchBot\Client\Mandate as MandateClient;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\Salesforce18Id;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\RoutableMessageBus;

/**
 * Sends mandates to Salesforce.
 */
#[AsMessageHandler]
readonly class MandateUpsertedHandler
{
    private const int MAX_SALEFORCE_FIELD_UPDATE_TRIES = 3;

    public function __construct(
        private LoggerInterface $logger,
        private MandateClient $client,
        private RoutableMessageBus $bus,
        private DonationRepository $donationRepository,
        private EntityManagerInterface $entityManager,
        private Clock $clock,
    ) {
    }

    public function __invoke(MandateUpserted $message): void
    {
        // Assertion not really needed but added for reassurance - the Identity Map should be empty as we clear it in a
        // listener for the \Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent.
        Assertion::eq([], $this->entityManager->getUnitOfWork()->getIdentityMap());

        $uuid = $message->uuid;
        $this->logger->info("MUH invoked for UUID: $uuid");

        try {
            $sfId = $this->client->createOrUpdate($message);
            $this->setSalesforceFieldsWithRetry($message, $sfId);

            $donations = $this->donationRepository->findAllForMandate(Uuid::fromString($message->uuid));

            // This may be the first time that we are able to update the donations in SF, it wasn't possible before
            // if the mandate did not have an SF ID.

            foreach ($donations as $donationInMandate) {
                Assertion::notNull($donationInMandate->getMandate()?->getSalesforceId(), 'Expected mandate to have SF ID after push');
                $this->bus->dispatch(DonationUpserted::fromDonationEnveloped($donationInMandate));
            }
        } catch (NotFoundException $_exception) {
            // Thrown only for *sandbox* 404s -> quietly stop trying to push mandate to a removed campaign.
            Assertion::notEq(\MatchBot\Application\Environment::Production, \MatchBot\Application\Environment::current());
            $this->logger->info(
                "Marking 404 campaign Salesforce mandate {$message->uuid} as complete; won't push again."
            );
            $this->setSalesforceFieldsWithRetry($message, null);

            return;
        } catch (BadRequestException | BadResponseException $exception) {
            // no trace needed for these exception types.
            $this->logger->error(sprintf(
                "MUH: %s on attempt to push mandate %s: %s",
                get_class($exception),
                $uuid,
                $exception->getMessage(),
            ));
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf(
                "MUH: Exception %s on attempt to push mandate %s: %s. Trace: %s",
                get_class($exception),
                $uuid,
                $exception->getMessage(),
                $exception->getTraceAsString(),
            ));
        }
    }

    /**
     * Try to safely set Salesforce ID, and other push tracking fields. If it
     * fails repeatedly, this should be safe to leave for a later update.
     * Salesforce has UUIDs so we won't lose the ability to reconcile the records.
     *
     * @param Salesforce18Id<RegularGivingMandate>|null $salesforceId
     */
    private function setSalesforceFieldsWithRetry(
        MandateUpserted $changeMessage,
        ?Salesforce18Id $salesforceId
    ): void {
        $tries = 0;
        $uuid = $changeMessage->uuid;

        do {
            try {
                if ($tries > 0) {
                    $this->logger->info("Retrying setting Salesforce fields for mandate $uuid after $tries tries");
                }
                $this->setSalesforceFields($uuid, $salesforceId);
                return;
            } catch (DBALException\RetryableException $exception) {
                $this->logger->info(sprintf(
                    '%s: Lock unavailable to set Salesforce fields on mandate %s with Salesforce ID %s on try #%d',
                    get_class($exception),
                    $uuid,
                    $salesforceId->value ?? 'null',
                    $tries,
                ));
            } catch (DBALException\ConnectionLost $exception) {
                // Seen only at fairly quiet times *and* before we increased DB wait_timeout from 8 hours
                // to just over workers' max lifetime of 24 hours. Should happen rarely or never with new DB config.
                $this->logger->warning(sprintf(
                    '%s: Connection lost while setting Salesforce fields on donation %s, try #%d',
                    get_class($exception),
                    $uuid,
                    $tries,
                ));
            }

            $tries++;
        } while ($tries < self::MAX_SALEFORCE_FIELD_UPDATE_TRIES);

        $this->logger->error(
            "Failed to set Salesforce fields for mandate $uuid after $tries tries"
        );
    }

    /**
     * Consider DRYing up duplication with DoctrineDonationRepository::setSalesforceFields before
     * making a third copy
     *
     * @param Salesforce18Id<RegularGivingMandate> $salesforceId
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
