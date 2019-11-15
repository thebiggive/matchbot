<?php

declare(strict_types=1);

namespace MatchBot\Application\Matching;

use MatchBot\Domain\CampaignFunding;

abstract class Adapter
{
    /** @var bool */
    private $inTransaction = false;
    /** @var string[] Amounts as bcmath precision strings. Associative, keyed on fund ID. */
    private $knownAmounts = [];

    /**
     * @param callable $function
     * @return mixed The given `$function`'s return value
     */
    abstract public function doRunTransactionally(callable $function);
    abstract protected function doGetAmount(CampaignFunding $funding): string;
    abstract protected function doSetAmount(CampaignFunding $funding, string $amount): bool;

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
     * Gets amount available in the fund, looking it up from the store only the first time each fund is used in the
     * transaction.
     *
     * @param CampaignFunding $funding
     * @return string
     */
    public function getAmount(CampaignFunding $funding): string
    {
        if (!$this->inTransaction) {
            throw new \LogicException('Matching adapter work must be in a transaction');
        }

        if (!isset($this->knownAmounts[$funding->getId()])) {
            $this->knownAmounts[$funding->getId()] = $this->doGetAmount($funding);
        }

        return $this->knownAmounts[$funding->getId()];
    }

    public function addAmount(CampaignFunding $funding, string $amount): void
    {
        if (!$this->inTransaction) {
            throw new \LogicException('Matching adapter work must be in a transaction');
        }

        $startAmount = $this->getAmount($funding);
        $newAmount = bcadd($startAmount, $amount, 2);

        $this->doSetAmount($funding, $amount);

        $this->knownAmounts[$funding->getId()] = $newAmount;
    }

    public function subtractAmount(CampaignFunding $funding, string $amount): void
    {
        if (!$this->inTransaction) {
            throw new \LogicException('Matching adapter work must be in a transaction');
        }

        $startAmount = $this->getAmount($funding);
        $newAmount = bcsub($startAmount, $amount, 2);

        $this->doSetAmount($funding, $newAmount);

        $this->knownAmounts[$funding->getId()] = $newAmount;
    }
}
