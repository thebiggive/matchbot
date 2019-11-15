<?php

declare(strict_types=1);

namespace MatchBot\Application\Matching;

use MatchBot\Domain\CampaignFunding;

abstract class Adapter
{
    /** @var bool */
    private $started = false;
    /** @var string[] Amounts as bcmath precision strings. Associative, keyed on fund ID. */
    private $knownAmounts = [];

    abstract protected function doStart(): void;
    abstract protected function doFinish(): void;
    abstract protected function doGetAmount(CampaignFunding $funding): string;
    abstract protected function doSetAmount(CampaignFunding $funding, string $amount): void;

    public function start(): void
    {
        $this->doStart();
        $this->started = true;
    }

    public function finish(): void
    {
        $this->doFinish();
        $this->started = false;
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
        if (!$this->started) {
            throw new \LogicException('Matching adapter work must be between start() and finish() calls');
        }

        if (!isset($this->knownAmounts[$funding->getId()])) {
            $this->knownAmounts[$funding->getId()] = $this->doGetAmount($funding);
        }

        return $this->knownAmounts[$funding->getId()];
    }

    public function addAmount(CampaignFunding $funding, string $amount): void
    {
        if (!$this->started) {
            throw new \LogicException('Matching adapter work must be between start() and finish() calls');
        }

        $startAmount = $this->getAmount($funding);
        $newAmount = bcadd($startAmount, $amount, 2);

        $this->doSetAmount($funding, $amount);

        $this->knownAmounts[$funding->getId()] = $newAmount;
    }

    public function subtractAmount(CampaignFunding $funding, string $amount): void
    {
        if (!$this->started) {
            throw new \LogicException('Matching adapter work must be between start() and finish() calls');
        }

        $startAmount = $this->getAmount($funding);
        $newAmount = bcsub($startAmount, $amount, 2);

        $this->doSetAmount($funding, $newAmount);

        $this->knownAmounts[$funding->getId()] = $newAmount;
    }
}
