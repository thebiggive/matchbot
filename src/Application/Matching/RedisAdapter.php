<?php

declare(strict_types=1);

namespace MatchBot\Application\Matching;

use Doctrine\ORM\EntityManagerInterface;
use malkusch\lock\exception\ExecutionOutsideLockException;
use malkusch\lock\exception\LockAcquireException;
use malkusch\lock\exception\LockReleaseException;
use malkusch\lock\mutex\Mutex;
use malkusch\lock\mutex\PHPRedisMutex;
use MatchBot\Domain\CampaignFunding;
use Redis;

class RedisAdapter extends Adapter
{
    /** @var EntityManagerInterface */
    private $entityManager;
    /** @var CampaignFunding[] */
    private $fundingsToPersist;
    /** @var Mutex */
    private $mutex;
    /** @var Redis */
    private $redis;

    public function __construct(Redis $redis, EntityManagerInterface $entityManager)
    {
        $this->redis = $redis;
        $this->entityManager = $entityManager;
    }

    public function doRunTransactionally(callable $function)
    {
        try {
            $result = $this->getMutex()->synchronized($function);
        } catch (ExecutionOutsideLockException $exception) {
            throw new RetryableLockException('Execution within lock window did not complete');
        } catch (LockAcquireException $exception) {
            throw new RetryableLockException('Could not acquire lock');
        } catch (LockReleaseException $exception) {
            throw new TerminalLockException('Could not release lock - ' . $exception->getMessage(), 500, $exception);
        }

        $this->saveFundingsToDatabase();

        return $result;
    }

    protected function doGetAmount(CampaignFunding $funding): string
    {
        $value = $this->getMutex()->synchronized(function () use ($funding) {
            return $this->redis->get($this->buildKey($funding));
        });

        if ($value === false) {
            $value = $funding->getAmountAvailable();
            if (!$this->doSetAmount($funding, $value)) {
                throw new \LogicException('Could not set initial value in Redis');
            }
        }

        return $value;
    }

    public function doSetAmount(CampaignFunding $funding, string $amount): bool
    {
        $setResult = $this->getMutex()->check(function () use ($funding, $amount) {
            return $this->redis->set($this->buildKey($funding), $amount);
        });

        if (!$setResult) {
            return false;
        }

        $funding->setAmountAvailable($amount);
        $this->fundingsToPersist[] = $funding;

        return true;
    }

    private function buildKey(CampaignFunding $funding)
    {
        return "fund-{$funding->getId()}-available";
    }

    /**
     * After completing fund allocation, update the database funds available to our last known values, without locks.
     * This is not guaranteed to *always* be a match for the real-time Redis store since we make no effort to fix race
     * conditions on the database when using Redis as the source of truth for matching allocation.
     */
    private function saveFundingsToDatabase(): void
    {
        foreach ($this->fundingsToPersist as $funding) {
            $this->entityManager->persist($funding);
        }
    }

    private function getMutex(): Mutex
    {
        if (!isset($this->mutex)) {
            // To keep things simple and accurate, for now we have Redis updates all wait for a single, global lock
            // mutex. This is because doing it per-fund means keeping acquiring multiple locks in different
            // permutations, which is messy which the current Lock library and risks setting up deadlock scenarios.
            $this->mutex = new PHPRedisMutex([$this->redis], 'funds', 2); // 2 second timeout
        }

        return $this->mutex;
    }
}
