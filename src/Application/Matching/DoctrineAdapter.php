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
            $result = $this->entityManager->transactional($function);
        } catch (DBALException $exception) {
            throw $this->buildLockException($exception);
        }

        // Work around Doctrine bailing out of transaction with bools when we expect an array of withdrawals.
        return (is_bool($result) ? [] : $result);
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

    protected function doAddAmount(CampaignFunding $funding, string $amount): string
    {
        $newAmount = bcadd($funding->getAmountAvailable(), $amount, 2);
        $this->setAmount($funding, $newAmount);

        return $newAmount;
    }

    protected function doSubtractAmount(CampaignFunding $funding, string $amount): string
    {
        $newAmount = bcsub($funding->getAmountAvailable(), $amount, 2);
        $this->setAmount($funding, $newAmount);

        return $newAmount;
    }

    private function setAmount(CampaignFunding $funding, string $amount): void
    {
        try {
            $funding->setAmountAvailable($amount);
            $this->entityManager->persist($funding);
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
