<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;

abstract class SalesforceWriteProxyRepository extends SalesforceProxyRepository
{
    abstract public function doPush(SalesforceWriteProxy $proxy): bool;

    public function push(SalesforceWriteProxy $proxy): bool
    {
        $proxy->setSalesforcePushStatus('pending');

        $success = $this->doPush($proxy);

        if ($success) {
            $proxy->setSalesforceLastPush(new DateTime('now'));
            $proxy->setSalesforcePushStatus('complete');
        }

        return $success;
    }
}
