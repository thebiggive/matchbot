<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;

abstract class SalesforceProxyRepository
{
    abstract public function doPull(SalesforceProxy $proxy): SalesforceProxy;

    public function pull(SalesforceProxy $proxy): SalesforceProxy
    {
        $proxy = $this->doPull($proxy);
        $proxy->setSalesforceLastPull(new DateTime('now'));

        return $proxy;
    }
}
