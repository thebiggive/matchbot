<?php

declare(strict_types=1);

namespace MatchBot\Application\Matching;

use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\Pure;
use MatchBot\Domain\CampaignFunding;
use Redis;

/**
 * This adapter does not lock but uses atomic Redis `MULTI` operations to detect parallel changes in allocations.
 * It has an internal retry / adjust mechanism to handle the case where a fund's just running out and the database
 * copy of the amount available was out of date.
 *
 * As this is adapter has now been well tested and is the only one we're using, the alternative
 * transactional `DoctrineAdapter` is now removed. It can be viewed in its final state from 2020 at
 * https://github.com/thebiggive/matchbot/blob/b3a861c97190ac91d073aa86530401958c816e74/src/Application/Matching/DoctrineAdapter.php
 */
class OptimisticRedisAdapter extends Adapter
{
    /**
     * @param Redis $redis
     * @param EntityManagerInterface $entityManager
     * @param array $fundingsToPersist
     * @param int $maxPartialAllocateTries -
     *            How many seconds the authoritative source for real-time match funds should keep data, as a minimum.
     *            Because Redis sets an updated value on each change to the balance, the case where using the database
     *            value could be problematic (race conditions with high volume access) should not overlap with the case
     *            where Redis copies of available fund balances are expired and have to be re-fetched.
     *
     * @param int $storageDurationSeconds
     *            Number of times to immediately try to allocate a smaller amount if the fund's running low
     */
    #[Pure]
    public function __construct(
        private Redis $redis,
        private EntityManagerInterface $entityManager,
        private array $fundingsToPersist = [],
        private int $maxPartialAllocateTries = 5,
        private int $storageDurationSeconds = 86_400 // 1 day
    ) {
    }

    #[Pure]
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

    #[Pure]
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

    #[Pure]
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

    public function delete(CampaignFunding $funding): void
    {
        $this->redis->del($this->buildKey($funding));
    }

    private function toPence(string $pounds): int
    {
        return (int) bcmul($pounds, '100', 0);
    }

    private function toPounds(int $pence): string
    {
        return bcdiv((string) $pence, '100', 2);
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
