<?php

declare(strict_types=1);

namespace MatchBot\Application\Matching;

use MatchBot\Domain\CampaignFunding;

abstract class Adapter
{
    /** @var bool */
    private $inTransaction = false;

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

    public function addAmount(CampaignFunding $funding, string $amount): string
    {
        if (!$this->inTransaction) {
            throw new \LogicException('Matching adapter work must be in a transaction');
        }

        return $this->doAddAmount($funding, $amount);
    }

    public function subtractAmount(CampaignFunding $funding, string $amount): string
    {
        if (!$this->inTransaction) {
            throw new \LogicException('Matching adapter work must be in a transaction');
        }

        return $this->doSubtractAmount($funding, $amount);
    }
}
