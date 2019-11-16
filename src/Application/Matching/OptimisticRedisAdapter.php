<?php

declare(strict_types=1);

namespace MatchBot\Application\Matching;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\CampaignFunding;
use Redis;

/**
 * Keep in mind that because this adapter is not actually locking, it must *NOT* throw the RetryableLockException!
 * It won't roll back existing allocations so further allocation cannot proceed safely if this were to happen. This
 * is the rationale for having an internal retry / adjust mechanism in this adapter to handle the case where a fund's
 * just running out and the database copy of the amount available was out of date.
 */
class OptimisticRedisAdapter extends Adapter
{
    /** @var EntityManagerInterface */
    private $entityManager;
    /** @var CampaignFunding[] */
    private $fundingsToPersist = [];
    /** @var int Number of times to immediately try to allocate a smaller amount if the fund's running low */
    private $maxPartialAllocateTries = 5;
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

        [$initResponse, $fundBalanceInPence] = $this->redis->multi()
            ->setnx($this->buildKey($funding), $this->getPenceAvailable($funding)) // Init if new to Redis
            ->decrBy($this->buildKey($funding), $decrementInPence)
            ->exec();

        if ($fundBalanceInPence < 0) {
            // We have hit the edge case where not having strict, slow locks falls down. We atomically
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

            $retries = 0;
            $amountAllocatedInPence = $decrementInPence;
            while ($retries++ < $this->maxPartialAllocateTries && $fundBalanceInPence < 0) {
                // Try deallocating just the difference until the fund has exactly zero
                $overspendInPence = 0 - $fundBalanceInPence;
                $fundBalanceInPence = $this->redis->incrBy($this->buildKey($funding), $overspendInPence);
                $amountAllocatedInPence -= $overspendInPence;
            }

            if ($fundBalanceInPence < 0) {
                // We couldn't get the values to work within the maximum number of iterations, so release whatever
                // we tried to hold back to the match pot and bail out.
                $fundBalanceInPence = $this->redis->incrBy($this->buildKey($funding), $amountAllocatedInPence);
                $this->setFundingValue($funding, (string) ($fundBalanceInPence / 100));
                throw new TerminalLockException(
                    "Fund {$funding->getId()} balance sub-zero after $retries attempts. " .
                    "Releasing final $amountAllocatedInPence pence"
                );
            }

            $this->setFundingValue($funding, (string) ($fundBalanceInPence / 100));
            throw new LessThanRequestedAllocatedException(
                (string) ($amountAllocatedInPence / 100),
                (string) ($fundBalanceInPence / 100)
            );
        }

        $fundBalance = (string) ($fundBalanceInPence / 100);
        $this->setFundingValue($funding, $fundBalance);

        return $fundBalance;
    }

    public function doAddAmount(CampaignFunding $funding, string $amount): string
    {
        $incrementInPence = (int) ((float) $amount * 100);

        [$initResponse, $fundBalanceInPence] = $this->redis->multi()
            ->setnx($this->buildKey($funding), $this->getPenceAvailable($funding)) // Init if new to Redis
            ->incrBy($this->buildKey($funding), $incrementInPence)
            ->exec();

        $fundBalance = (string) ($fundBalanceInPence / 100);
        $this->setFundingValue($funding, $fundBalance);

        return $fundBalance;
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
        $this->entityManager->transactional(function () {
            foreach ($this->fundingsToPersist as $funding) {
                $this->entityManager->persist($funding);
            }
        });
    }

    private function setFundingValue(CampaignFunding $funding, string $newValue): void
    {
        $funding->setAmountAvailable($newValue);
        if (!in_array($funding, $this->fundingsToPersist, true)) {
            $this->fundingsToPersist[] = $funding;
        }
    }
}
