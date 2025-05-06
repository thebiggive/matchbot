<?php

declare(strict_types=1);

namespace MatchBot\Application\Matching;

use MatchBot\Application\Assertion;
use MatchBot\Application\RealTimeMatchingStorage;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\Donation;
use Psr\Log\LoggerInterface;

/**
 * This adapter does not lock but uses atomic Redis `MULTI` operations to detect parallel changes in allocations.
 * It has an internal retry / adjust mechanism to handle the case where a fund's just running out and the database
 * copy of the amount available was out of date.
 *
 * As this adapter has now been well tested and is the only one we're using, the alternative
 * transactional `DoctrineAdapter` is now removed. It can be viewed in its final state from 2020 at
 * https://github.com/thebiggive/matchbot/blob/b3a861c97190ac91d073aa86530401958c816e74/src/Application/Matching/DoctrineAdapter.php
 */
class Adapter
{
    /** @var int Number of times to immediately try to allocate a smaller amount if the fund's running low */
    private int $maxPartialAllocateTries = 5;
    /**
     * @var int How many seconds the authoritative source for real-time match funds should keep data, as a minimum.
     *          Because Redis sets an updated value on each change to the balance, the case where using the database
     *          value could be problematic (race conditions with high volume access) should not overlap with the case
     *          where Redis copies of available fund balances are expired and have to be re-fetched.
     */
    public const int STORAGE_DURATION_SECONDS = 86_400; // 1 day

    public const array REDIS_OPTIONS_FOR_LIMITED_DURATION_STORAGE = [
        'nx', // Only set the key if it doesn't exist already in redis, i.e seen for the first time or record expired.
        'ex' => self::STORAGE_DURATION_SECONDS // expire after given duration.
    ];

    /**
     * @var list<array{campaignFunding: CampaignFunding, amount:numeric-string}>
     */
    private array $amountsSubtractedInCurrentProcess = [];

    public function __construct(
        private RealTimeMatchingStorage $storage,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Callers are responsible for flushing after this works on `CampaignFunding`s – typically once per larger operation
     * if looping over many.
     *
     * @param CampaignFunding $funding
     * @param numeric-string $amount
     * @return numeric-string New fund balance
     */
    public function addAmount(CampaignFunding $funding, string $amount): string
    {
        $incrementFractional = $this->toCurrencyFractionalUnit($amount);

        /**
         * @psalm-suppress PossiblyInvalidArrayAccess
         * @psalm-suppress PossiblyFalseReference - we know incrBy will retrun an array in multi mode
         * @psalm-suppress PossiblyInvalidMethodCall
         */
        [$_initResponse, $fundBalanceFractional] = $this->storage->multi()
            ->set(
                $this->buildKey($funding),
                $this->toCurrencyFractionalUnit($funding->getAmountAvailable()),
                self::REDIS_OPTIONS_FOR_LIMITED_DURATION_STORAGE,
            )
            ->incrBy($this->buildKey($funding), $incrementFractional)
            ->exec();

        $fundBalance = $this->toCurrencyWholeUnit((int)$fundBalanceFractional);
        $funding->setAmountAvailable($fundBalance);

        return $fundBalance;
    }

    /**
     * Acts on the `CampaignFunding` Doctrine entity, as well as the Redis fund store. But
     * doesn't flush the database changes on its own.
     *
     * @param CampaignFunding $funding
     * @param numeric-string $amount
     * @return numeric-string New fund balance as bcmath-ready string
     */
    public function subtractAmount(CampaignFunding $funding, string $amount): string
    {
        $decrementFractional = $this->toCurrencyFractionalUnit($amount);

        /**
         * @psalm-suppress PossiblyFalseReference - in mulit mode decrBy will not return false.
         * @psalm-suppress PossiblyInvalidArrayAccess - in this case we know exec returns array
         * @psalm-suppress PossiblyInvalidMethodCall
         */
        [$_initResponse, $fundBalanceFractional] = $this->storage->multi()
            ->set(
                $this->buildKey($funding),
                $this->toCurrencyFractionalUnit($funding->getAmountAvailable()),
                self::REDIS_OPTIONS_FOR_LIMITED_DURATION_STORAGE
            )
            ->decrBy($this->buildKey($funding), $decrementFractional)
            ->exec();

        $fundBalanceFractional = (int)$fundBalanceFractional;
        if ($fundBalanceFractional < 0) {
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
            $amountAllocatedFractional = $decrementFractional;
            while ($retries++ < $this->maxPartialAllocateTries && $fundBalanceFractional < 0) {
                // Try deallocating just the difference until the fund has exactly zero
                $overspendFractional = 0 - $fundBalanceFractional;
                /** @psalm-suppress InvalidCast - not in Redis Multi Mode */
                $fundBalanceFractional = (int)$this->storage->incrBy($this->buildKey($funding), $overspendFractional);
                $amountAllocatedFractional -= $overspendFractional;
            }

            if ($fundBalanceFractional < 0) {
                // We couldn't get the values to work within the maximum number of iterations, so release whatever
                // we tried to hold back to the match pot and bail out.
                /** @psalm-suppress InvalidCast not in multi mode * */
                $fundBalanceFractional = (int)$this->storage->incrBy(
                    $this->buildKey($funding),
                    $amountAllocatedFractional,
                );
                $funding->setAmountAvailable($this->toCurrencyWholeUnit($fundBalanceFractional));
                throw new TerminalLockException(
                    "Fund {$funding->getId()} balance sub-zero after $retries attempts. " .
                    "Releasing final $amountAllocatedFractional 'cents'"
                );
            }

            $funding->setAmountAvailable($this->toCurrencyWholeUnit($fundBalanceFractional));
            throw new LessThanRequestedAllocatedException(
                $this->toCurrencyWholeUnit($amountAllocatedFractional)
            );
        }

        $this->amountsSubtractedInCurrentProcess[] = ['campaignFunding' => $funding, 'amount' => $amount];

        $fundBalance = $this->toCurrencyWholeUnit($fundBalanceFractional);
        $funding->setAmountAvailable($fundBalance);

        return $fundBalance;
    }

    /**
     * Get a snapshot of the amount of match funds available in the given `$funding`. This should not be used to start
     * allocation maths except in emergencies where things appear to have got out of sync, because there is no
     * guarantee with this function that another thread will not reserve or release funds before you have finished
     * your work. You should instead use `addAmount()` and `subtractAmount()` which are built to work atomically or
     * transactionally so that they are safe for high-volume, multi-thread use.
     *
     * @param CampaignFunding $funding
     * @return numeric-string Amount available
     */
    public function getAmountAvailable(CampaignFunding $funding): string
    {
        $redisFundBalanceFractional = $this->storage->get($this->buildKey($funding));
        \assert(! $redisFundBalanceFractional instanceof RealTimeMatchingStorage); // not in multi mode

        if ($redisFundBalanceFractional === false) {
            // No value in Redis -> may well have expired after 24 hours. Consult the DB for the
            // stable value. This will often happen for old or slower moving campaigns.
            return $funding->getAmountAvailable();
        }

        // Redis INCRBY / DECRBY and friends work on values which are validated to be integer-like
        // but are actually stored as strings internally, and seem to come back to PHP as strings
        // when get() is used => cast to int before converting to pounds.
        return $this->toCurrencyWholeUnit((int) $redisFundBalanceFractional);
    }

    public function delete(CampaignFunding $funding): void
    {
        $this->storage->del($this->buildKey($funding));
    }

    /**
     * Converts e.g. pounds to pence – but is currency-agnostic except for currently assuming
     * a 100-fold multiplication is reasonable.
     *
     * @param numeric-string $wholeUnit e.g. pounds, dollars.
     * @return int  e.g. pence, cents.
     */
    private function toCurrencyFractionalUnit(string $wholeUnit): int
    {
        return (int) bcmul($wholeUnit, '100', 0);
    }

    /**
     * Converts e.g. pence to pounds – but is currency-agnostic except for currently assuming
     * a 100-fold division is reasonable.
     *
     * @param int $fractionalUnit   e.g. pence, cents.
     * @psalm-return numeric-string   e.g. pounds, dollars.
     */
    private function toCurrencyWholeUnit(int $fractionalUnit): string
    {
        return bcdiv((string) $fractionalUnit, '100', 2);
    }

    private function buildKey(CampaignFunding $funding): string
    {
        $id = $funding->getId();
        Assertion::notNull($id, "Funding ID must be non-null to build key");

        return "fund-{$id}-available-opt";
    }

    /**
     * For use only in case of errors, to release allocated funds in redis that would otherwise be out of sync with
     * what we have in MySQL.
     */
    public function releaseNewlyAllocatedFunds(): void
    {
        foreach ($this->amountsSubtractedInCurrentProcess as $fundingAndAmount) {
            $amount = $fundingAndAmount['amount'];
            $funding = $fundingAndAmount['campaignFunding'];

            $this->logger->warning("Released newly allocated funds of $amount for funding ID {$funding->getId()}");

            $this->addAmount($funding, $amount);
        }
    }

    /**
     * @return numeric-string The total amount released
     */
    public function releaseAllFundsForDonation(Donation $donation): string
    {
        $totalAmountReleased = '0.00';
        foreach ($donation->getFundingWithdrawals() as $fundingWithdrawal) {
            $funding = $fundingWithdrawal->getCampaignFunding();
            $fundingWithDrawalAmount = $fundingWithdrawal->getAmount();

            $newTotal = $this->addAmount($funding, $fundingWithDrawalAmount);
            $totalAmountReleased = bcadd($totalAmountReleased, $fundingWithDrawalAmount, 2);

            $this->logger->info("Released {$fundingWithDrawalAmount} to funding {$funding->getId()}");
            $this->logger->info("New fund total for {$funding->getId()}: $newTotal");
        }

        return $totalAmountReleased;
    }
}
