<?php

namespace MatchBot\Application\Messenger\Handler;

use Assert\Assertion;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Application\Messenger\MandateUpserted;
use MatchBot\Client\BadRequestException;
use MatchBot\Client\BadResponseException;
use MatchBot\Client\Mandate as MandateClient;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\Salesforce18Id;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Sends mandates to Salesforce.
 */
#[AsMessageHandler]
readonly class MandateUpsertedHandler
{
    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
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
            $this->setSalesforceFields($uuid, $sfId);

            $donations = $this->donationRepository->findAllForMandate(Uuid::fromString($message->uuid));

            // This may be the first time that we are able to update the donations in SF, it wasn't possible before
            // if the mandate did not have an SF ID.

            foreach ($donations as $donationInMandate) {
                Assertion::notNull($donationInMandate->getMandate()?->getSalesforceId(), 'Expected mandate to have SF ID after push');
                $this->bus->dispatch(DonationUpserted::fromDonationEnveloped($donationInMandate));
            }
        } catch (BadRequestException | BadResponseException | NotFoundException $exception) {
            // no trace needed for these exception types.
            $this->logger->error(sprintf(
                "MUH: %s on attempt to push donation %s: %s",
                get_class($exception),
                $uuid,
                $exception->getMessage(),
            ));
        } catch (\Throwable $exception) {
            $this->logger->error(sprintf(
                "MUH: Exception %s on attempt to push donation %s: %s. Trace: %s",
                get_class($exception),
                $uuid,
                $exception->getMessage(),
                $exception->getTraceAsString(),
            ));
        }
    }

    /**
     * Consider DRYing up duplication with DoctrineDonationRepository::setSalesforceFields before
     * making a third copy
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
