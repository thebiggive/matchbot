<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;

abstract class SalesforceWriteProxyRepository extends SalesforceProxyRepository
{
    abstract public function doPush(SalesforceWriteProxy $proxy): bool;

    public function push(SalesforceWriteProxy $proxy): bool
    {
        $this->logInfo('Pushing ' . get_class($proxy) . ' ' . $proxy->getId() . '...');
        $this->prePush($proxy);

        $success = $this->doPush($proxy);

        $this->postPush($success, $proxy);

        return $success;
    }

    protected function prePush(SalesforceWriteProxy $proxy): void
    {
        $proxy->setSalesforcePushStatus('pending');
    }

    protected function postPush(bool $success, SalesforceWriteProxy $proxy): void
    {
        if ($success) {
            $proxy->setSalesforceLastPush(new DateTime('now'));
            $proxy->setSalesforcePushStatus('complete');
            $this->logInfo('...pushed ' . get_class($proxy) . " {$proxy->getId()}: SF ID {$proxy->getSalesforceId()}");
        } else {
            $this->logError('...error pushing ' . get_class($proxy) . ' ' . $proxy->getId());
        }

        $this->getEntityManager()->persist($proxy);
        $this->getEntityManager()->flush();
    }
}
