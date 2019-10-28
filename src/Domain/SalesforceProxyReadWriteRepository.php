<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;

abstract class SalesforceProxyReadWriteRepository extends SalesforceProxyRepository
{
    abstract public function doPush(SalesforceProxyReadWrite $proxy): bool;

    public function push(SalesforceProxyReadWrite $proxy): bool
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
