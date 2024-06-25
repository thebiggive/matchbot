<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Query\QueryBuilder;
use MatchBot\Application\Messenger\AbstractStateChanged;
use MatchBot\Client;

/**
 * @template T of SalesforceWriteProxy
 * @template C of Client\Common
 * @template-extends SalesforceProxyRepository<T, C>
 */
abstract class SalesforceWriteProxyRepository extends SalesforceProxyRepository
{
    /**
     * @return string|null  Salesforce ID on success; null otherwise
     */
    abstract public function doCreate(AbstractStateChanged $changeMessage): ?string;

    abstract public function doUpdate(AbstractStateChanged $changeMessage): bool;

    public function push(AbstractStateChanged $changeMessage, bool $isNew): void
    {
        if ($isNew) {
            $newSalesforceId = $this->doCreate($changeMessage);
            if ($newSalesforceId !== null) { // May be null if missing campaign skipped in sandbox
                $this->setIdAndLastPush($changeMessage->uuid, $newSalesforceId);
            }

            return;
        }

        if ($changeMessage->salesforceId === null) {
            $this->logWarning(sprintf(
                'Create-updating from %s %s due to a likely past error',
                get_class($changeMessage),
                $changeMessage->uuid,
            ));

            $newSalesforceId = $this->doCreate($changeMessage); // Sets SF ID ready for update.
            if ($newSalesforceId !== null) {
                if ($this->doUpdate($changeMessage)) {
                    $this->setIdAndLastPush($changeMessage->uuid, $newSalesforceId);
                }
            } // else created failed again, still no SF ID. Don't try to update for now.

            return;
        }

        // SF ID already set as expected -> normal update scenario
        if ($this->doUpdate($changeMessage)) {
            $this->setLastPush($changeMessage->uuid); // No lock needed.
        }
    }

    /**
     * Runs with row lock; Salesforce ID is the one DB thing from pushing that other threads must not clear.
     *
     * @throws RetryableException if another thread had the lock 3 times.
     */
    private function setIdAndLastPush(string $uuid, string $newSalesforceId, int $triesLeft = 3): void
    {
        try {
            $entityManager = $this->getEntityManager();
            /** @var SalesforceWriteProxy $proxy */
            $proxy = $this->findOneBy(['uuid' => $uuid]);

            $entityManager->beginTransaction();
            $entityManager->refresh($proxy, LockMode::PESSIMISTIC_WRITE);

            $proxy->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_COMPLETE);
            $proxy->setSalesforceLastPush(new \DateTime('now'));
            $proxy->setSalesforceId($newSalesforceId);

            $entityManager->persist($proxy);
            $entityManager->flush();
            $entityManager->commit();
        } catch (RetryableException $exception) {
            if ($triesLeft === 0) {
                throw $exception;
            }

            $this->logger->info(
                "setIdAndLastPush: RetryableException on attempt to set ID and last push for $uuid, will retry \n" .
                $exception
            );
            $this->setIdAndLastPush($uuid, $newSalesforceId, $triesLeft - 1);
        }
    }

    private function setLastPush(string $uuid): void
    {
        $qb = new QueryBuilder($this->getEntityManager()->getConnection());
        $qb->update($this->getEntityName())
            ->set('salesforcePushStatus', ':status')
            ->set('salesforceLastPush', ':now')
            ->where('uuid = :uuid')
            ->setParameter('status', SalesforceWriteProxy::PUSH_STATUS_COMPLETE)
            ->setParameter('now', (new \DateTime('now'))->format('Y-m-d H:i:s'))
            ->setParameter('uuid', $uuid);

        echo $qb->getSQL();
        $qb->executeStatement();
    }
}
