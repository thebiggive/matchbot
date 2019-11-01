<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\EntityRepository;
use MatchBot\Client;

abstract class SalesforceProxyRepository extends EntityRepository
{
    /**
     * @var Client\Common
     */
    protected $client;

    public function setClient(Client\Common $client): void
    {
        $this->client = $client;
    }

    protected function getClient(): Client\Common
    {
        if (!$this->client) {
            throw new \LogicException('Set a Client in DI config for this Repository to sync data');
        }

        return $this->client;
    }
}
