<?php

declare(strict_types=1);

namespace MatchBot\Application\Matching;

use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Exception\RetryableException;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\CampaignFunding;

class DoctrineAdapter extends Adapter
{
    /** @var EntityManagerInterface */
    private $entityManager;

    public function __construct(EntityManagerInterface $entityManager)
    {
        $this->entityManager = $entityManager;
    }

    public function doRunTransactionally(callable $function)
    {
        try {
            return $this->entityManager->transactional($function);
        } catch (DBALException $exception) {
            throw $this->buildLockException($exception);
        }
    }

    protected function doGetAmount(CampaignFunding $fund): string
    {
        try {
            $this->entityManager->getRepository(CampaignFunding::class)->getOneWithWriteLock($fund);
        } catch (DBALException $exception) {
            try {
                // Release the lock before we return
                $this->entityManager->rollback();
            } catch (ConnectionException $rollbackException) {
                // Rollback bails out if transaction was already terminated
            }

            throw $this->buildLockException($exception);
        }

        return $fund->getAmountAvailable();
    }

    protected function doSetAmount(CampaignFunding $fund, string $amount): bool
    {
        try {
            $fund->setAmountAvailable($amount);
            $this->entityManager->persist($fund);

            return true;
        } catch (DBALException $exception) {
            throw $this->buildLockException($exception);
        }
    }

    private function buildLockException(\Exception $exception): LockException
    {
        $exceptionClass = ($exception instanceof RetryableException)
            ? RetryableLockException::class
            : TerminalLockException::class;

        return new $exceptionClass(
            'Doctrine exception ' . get_class($exception) . ': ' . $exception->getMessage(),
            503,
            $exception
        );
    }
}
