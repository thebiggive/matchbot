<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\EntityRepository;
use MatchBot\Client;
use Psr\Log\LoggerInterface;

/**
 * @template T of SalesforceProxy
 * @template-extends EntityRepository<T>
 */ abstract class SalesforceProxyRepository extends EntityRepository
{
    protected Client\Common $client;
    protected LoggerInterface $logger;

    protected function logError(string $message): void
    {
        $this->logger->error($message);
    }

    protected function logWarning(string $message): void
    {
        $this->logger->warning($message);
    }

    protected function logInfo(string $message): void
    {
        $this->logger->info($message);
    }

    public function setClient(Client\Common $client): void
    {
        $this->client = $client;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    protected function getClient(): Client\Common
    {
        if (!$this->client) {
            throw new \LogicException('Set a Client in DI config for this Repository to sync data');
        }

        return $this->client;
    }
}
