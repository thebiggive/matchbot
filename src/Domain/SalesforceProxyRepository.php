<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\EntityRepository;
use MatchBot\Client;
use Psr\Log\LoggerInterface;

/**
 * @template T of SalesforceProxy
 * @template C of Client\Common
 * @template-extends EntityRepository<T>
 *
 * @psalm-suppress MissingConstructor - these repositories have to have setters called after construction
 */
abstract class SalesforceProxyRepository extends EntityRepository
{
    /**
     * @psalm-var C
     */
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

    /**
     * @psalm-param C $client
     */
    public function setClient(Client\Common $client): void
    {
        $this->client = $client;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    /**
     * @psalm-return C
     */
    protected function getClient(): Client\Common
    {
        /** @psalm-suppress DocblockTypeContradiction */
        if (!$this->client) {
            throw new \LogicException('Set a Client in DI config for this Repository to sync data');
        }

        return $this->client;
    }
}
