<?php

declare(strict_types=1);

namespace MatchBot\Application\Matching;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\CampaignFunding;
use Redis;

class OptimisticRedisAdapter extends Adapter
{
    /** @var EntityManagerInterface */
    private $entityManager;
    /** @var CampaignFunding[] */
    private $fundingsToPersist = [];
    /** @var Redis */
    private $redis;

    public function __construct(Redis $redis, EntityManagerInterface $entityManager)
    {
        $this->redis = $redis;
        $this->entityManager = $entityManager;
    }

    public function doRunTransactionally(callable $function)
    {
        $result = $function();

        $this->saveFundingsToDatabase();

        return $result;
    }

    protected function doSubtractAmount(CampaignFunding $funding, string $amount): string
    {
        $decrementInPence = (int) (((float) $amount) * 100);

        list($initResponse, $newValueInPence) = $this->redis->multi()
            ->setnx($this->buildKey($funding), $this->getPenceAvailable($funding)) // Init if new to Redis
            ->decrBy($this->buildKey($funding), $decrementInPence)
            ->exec();

        if ($newValueInPence < 0) {
            // We have just hit the unlucky edge case where not having strict, slow locks falls down. We atomically
            // allocated some match funds based on the amount available when we queried the database, but since our
            // query somebody else got some match funds and now taking the amount we wanted would take the fund's
            // balance below zero.
            //
            // Fortunately, Redis's atomic operations mean we find out this happened straight away, and we know it's
            // always safe to release funds - there is no upper limit so atomically putting the funds back in the pot
            // cannot fail (except in service outages etc.)
            //
            // So, let's do exactly that and then fail in a way that tells the caller to retry, getting the new fund
            // total first. This is essentially a DIY optimistic lock exception.
            $this->doAddAmount($funding, $amount);

            throw new RetryableLockException('Fund balance would drop below zero to £' . ($newValueInPence / 100));
        }

        $newValue = (string) ($newValueInPence / 100);

        $funding->setAmountAvailable($newValue);
        $this->fundingsToPersist[] = $funding;

        return $newValue;
    }

    public function doAddAmount(CampaignFunding $funding, string $amount): string
    {
        $incrementInPence = (int) ((float) $amount * 100);

        list($initResponse, $newValueInPence) = $this->redis->multi()
            ->setnx($this->buildKey($funding), $this->getPenceAvailable($funding)) // Init if new to Redis
            ->incrBy($this->buildKey($funding), $incrementInPence)
            ->exec();

        $newValue = (string) ($newValueInPence / 100);

        $funding->setAmountAvailable($newValue);
        $this->fundingsToPersist[] = $funding;

        return $newValue;
    }

    private function buildKey(CampaignFunding $funding)
    {
        return "fund-{$funding->getId()}-available-opt";
    }

    private function getPenceAvailable(CampaignFunding $funding): int
    {
        return (int) (((float) $funding->getAmountAvailable()) * 100);
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
}
