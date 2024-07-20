<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use MatchBot\Application\Messenger\AbstractStateChanged;
use MatchBot\Client;

/**
 * @template T of SalesforceWriteProxy
 * @template C of Client\Common
 * @template-extends SalesforceProxyRepository<T, C>
 */
abstract class SalesforceWriteProxyRepository extends SalesforceProxyRepository
{
    abstract public function doCreate(AbstractStateChanged $changeMessage): bool;

    abstract public function doUpdate(AbstractStateChanged $changeMessage): bool;

    public function push(AbstractStateChanged $changeMessage, bool $isNew): void
    {
        if ($isNew) {
            if ($this->doCreate($changeMessage)) {
                $this->setLastPush($changeMessage->uuid);
            }

            return;
        }

        if ($this->doUpdate($changeMessage)) {
            $this->setLastPush($changeMessage->uuid); // No lock needed.
        }
    }

    private function setLastPush(string $uuid): void
    {
        $connection = $this->getEntityManager()->getConnection();
        try {
            $connection->executeStatement(
                <<<EOT
                UPDATE Donation SET salesforcePushStatus = 'complete', salesforceLastPush = NOW()
                WHERE uuid = :donationUUID
                LIMIT 1;
            EOT,
                ['donationUUID' => $uuid],
            );
        } catch (LockWaitTimeoutException $ex) {
            // A later thread can pick up the push if necessary.
            // TODO maybe build a generalised retry for the two non-critical SF DB patches.
            // And/or possibly consider not storing last push in the database? Queueing should
            // make this largely redundant.
            $this->logInfo(sprintf(
                'Lock unavailable to set donation %s Salesforce push status fields, will try later',
                $uuid,
            ));
        }
    }
}
