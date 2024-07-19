<?php

declare(strict_types=1);

namespace MatchBot\Domain;

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
        $connection->executeStatement(
            <<<EOT
                UPDATE Donation SET salesforcePushStatus = 'complete', salesforceLastPush = NOW()
                WHERE uuid = :donationUUID
                LIMIT 1;
            EOT,
            ['donationUUID' => $uuid],
        );
    }
}
