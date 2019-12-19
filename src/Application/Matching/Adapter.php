<?php

declare(strict_types=1);

namespace MatchBot\Application\Matching;

use MatchBot\Domain\CampaignFunding;

abstract class Adapter
{
    private bool $inTransaction = false;

    /**
     * Get a snapshot of the amount of match funds available in the given `$funding`. This should not be used to start
     * allocation maths except in emergencies where things appear to have got out of sync, because there is no
     * guarantee with this function that another thread will not reserve or release funds before you have finished
     * your work. You should instead use `addAmount()` and `subtractAmount()` which are built to work atomically or
     * transactionally so that they are safe for high-volume, multi-thread use.
     *
     * @param CampaignFunding $funding
     * @return string Amount available as bcmath-ready decimal string
     */
    abstract public function getAmountAvailable(CampaignFunding $funding): string;

    /**
     * @param callable $function
     * @return mixed The given `$function`'s return value
     */
    abstract protected function doRunTransactionally(callable $function);

    /**
     * Release funds atomically or within a transaction.
     *
     * @param CampaignFunding $funding
     * @param string $amount
     * @return string New fund balance as bcmath-ready string
     */
    abstract protected function doAddAmount(CampaignFunding $funding, string $amount): string;

    /**
     * Allocate funds atomically or within a transaction.
     *
     * @param CampaignFunding $funding
     * @param string $amount
     * @return string New fund balance as bcmath-ready string
     * @throws LessThanRequestedAllocatedException if the adapter allocated less than requested for matching
     */
    abstract protected function doSubtractAmount(CampaignFunding $funding, string $amount): string;

    /**
     * @param callable $function
     * @return mixed The given `$function`'s return value
     */
    public function runTransactionally(callable $function)
    {
        $this->inTransaction = true;
        $result = $this->doRunTransactionally($function);
        $this->inTransaction = false;

        return $result;
    }

    /**
     * @param CampaignFunding $funding
     * @param string $amount
     * @return string New fund balance as bcmath-ready string
     */
    public function addAmount(CampaignFunding $funding, string $amount): string
    {
        if (!$this->inTransaction) {
            throw new \LogicException('Matching adapter work must be in a transaction');
        }

        return $this->doAddAmount($funding, $amount);
    }

    /**
     * @param CampaignFunding $funding
     * @param string $amount
     * @return string New fund balance as bcmath-ready string
     */
    public function subtractAmount(CampaignFunding $funding, string $amount): string
    {
        if (!$this->inTransaction) {
            throw new \LogicException('Matching adapter work must be in a transaction');
        }

        return $this->doSubtractAmount($funding, $amount);
    }
}
