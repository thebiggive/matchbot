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
    private EntityManagerInterface $entityManager;
    /** @var CampaignFunding[] */
    private array $fundingsToPersist = [];
    /** @var int Number of times to immediately try to allocate a smaller amount if the fund's running low */
    private int $maxPartialAllocateTries = 5;
    private Redis $redis;
    /**
     * @var int How many seconds the authoritative source for real-time match funds should keep data, as a minimum.
     *          Because Redis sets an updated value on each change to the balance, the case where using the database
     *          value could be problematic (race conditions with high volume access) should not overlap with the case
     *          where Redis copies of available fund balances are expired and have to be re-fetched.
     */
    private static int $storageDurationSeconds = 86_400; // 1 day

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

    public function getAmountAvailable(CampaignFunding $funding): string
    {
        $redisFundBalanceInPence = $this->redis->get($this->buildKey($funding));
        if ($redisFundBalanceInPence === false) {
            // No value in Redis -> may well have expired after 24 hours. Consult the DB for the
            // stable value. This will often happen for old or slower moving campaigns.
            return $funding->getAmountAvailable();
        }

        // Redis INCRBY / DECRBY and friends work on values which are validated to be integer-like
        // but are actually stored as strings internally, and seem to come back to PHP as strings
        // when get() is used => cast to int before converting to pounds.
        return $this->toPounds((int) $redisFundBalanceInPence);
    }

    protected function doSubtractAmount(CampaignFunding $funding, string $amount): string
    {
        $decrementInPence = $this->toPence($amount);

        [$initResponse, $fundBalanceInPence] = $this->redis->multi()
            // Init if and only if new to Redis or expired (after 24 hours), using database value.
            ->set(
                $this->buildKey($funding),
                $this->toPence($funding->getAmountAvailable()),
                ['nx', 'ex' => static::$storageDurationSeconds],
            )
            ->decrBy($this->buildKey($funding), $decrementInPence)
            ->exec();

        $fundBalanceInPence = (int) $fundBalanceInPence;
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
                $fundBalanceInPence = (int) $this->redis->incrBy($this->buildKey($funding), $overspendInPence);
                $amountAllocatedInPence -= $overspendInPence;
            }

            if ($fundBalanceInPence < 0) {
                // We couldn't get the values to work within the maximum number of iterations, so release whatever
                // we tried to hold back to the match pot and bail out.
                $fundBalanceInPence = (int) $this->redis->incrBy($this->buildKey($funding), $amountAllocatedInPence);
                $this->setFundingValue($funding, $this->toPounds($fundBalanceInPence));
                throw new TerminalLockException(
                    "Fund {$funding->getId()} balance sub-zero after $retries attempts. " .
                    "Releasing final $amountAllocatedInPence pence"
                );
            }

            $this->setFundingValue($funding, $this->toPounds($fundBalanceInPence));
            throw new LessThanRequestedAllocatedException(
                $this->toPounds($amountAllocatedInPence),
                $this->toPounds($fundBalanceInPence)
            );
        }

        $fundBalance = $this->toPounds($fundBalanceInPence);
        $this->setFundingValue($funding, $fundBalance);

        return $fundBalance;
    }

    public function doAddAmount(CampaignFunding $funding, string $amount): string
    {
        $incrementInPence = $this->toPence($amount);

        [$initResponse, $fundBalanceInPence] = $this->redis->multi()
            // Init if and only if new to Redis or expired (after 24 hours), using database value.
            ->set(
                $this->buildKey($funding),
                $this->toPence($funding->getAmountAvailable()),
                ['nx', 'ex' => static::$storageDurationSeconds],
            )
            ->incrBy($this->buildKey($funding), $incrementInPence)
            ->exec();

        $fundBalance = $this->toPounds((int) $fundBalanceInPence);
        $this->setFundingValue($funding, $fundBalance);

        return $fundBalance;
    }

    private function toPence(string $pounds): int
    {
        return (int) bcmul($pounds, '100', 0);
    }

    private function toPounds(int $pence): string
    {
        return bcdiv($pence, '100', 2);
    }

    private function buildKey(CampaignFunding $funding)
    {
        return "fund-{$funding->getId()}-available-opt";
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
