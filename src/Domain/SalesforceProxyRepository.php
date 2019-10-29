<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\ORM\EntityRepository;

abstract class SalesforceProxyRepository extends EntityRepository
{
    abstract public function doPull(SalesforceProxy $proxy): SalesforceProxy;

    public function pull(SalesforceProxy $proxy): SalesforceProxy
    {
        $proxy = $this->doPull($proxy);
        $proxy->setSalesforceLastPull(new DateTime('now'));

        return $proxy;
    }
}
