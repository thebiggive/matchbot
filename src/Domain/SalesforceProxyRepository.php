<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\EntityRepository;
use MatchBot\Client;
use Psr\Log\LoggerInterface;

abstract class SalesforceProxyRepository extends EntityRepository
{
    protected Client\Common $client;
    protected LoggerInterface $logger;

    public function logError(string $message): void
    {
        if ($log = $this->getLogger()) {
            $log->error($message);
            return;
        }

        // Fallback if logged not configured
        echo "ERROR: $message";
    }

    public function logInfo(string $message): void
    {
        if ($log = $this->getLogger()) {
            $log->info($message);
            return;
        }

        // Fallback if logged not configured
        echo "INFO: $message";
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

    protected function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }
}
