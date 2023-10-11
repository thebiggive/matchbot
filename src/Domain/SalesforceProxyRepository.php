<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Mapping\ClassMetadata;
use MatchBot\Client;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @template T of SalesforceProxy
 * @template-extends EntityRepository<T>
 */ abstract class SalesforceProxyRepository extends EntityRepository
{
    protected Client\Common $client;
    protected LoggerInterface $logger;

    public function __construct(EntityManagerInterface $em, ClassMetadata $class)
    {
        $this->logger = new NullLogger();
        parent::__construct($em, $class);
    }

    protected function logError(string $message): void
    {
        if ($log = $this->getLogger()) {
            $log->error($message);
            return;
        }

        // Fallback if logger not configured
        echo "ERROR: $message";
    }

    protected function logWarning(string $message): void
    {
        if ($log = $this->getLogger()) {
            $log->warning($message);
            return;
        }

        // Fallback if logged not configured
        echo "WARNING: $message";
    }

    protected function logInfo(string $message): void
    {
        if ($log = $this->getLogger()) {
            $log->info($message);
            return;
        }

        // Fallback if logger not configured
        echo "INFO: $message";
    }

    public function setClient(Client\Common $client): void
    {
//        var_dump('set client: '. get_class($this). ', this_spl_id: ' . spl_object_id($this));
        $this->client = $client;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    protected function getClient(): Client\Common
    {
//        var_dump('get client:  '. get_class($this). ', this_spl_id: ' . spl_object_id($this));
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
