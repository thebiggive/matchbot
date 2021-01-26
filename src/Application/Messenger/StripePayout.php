<?php

namespace MatchBot\Application\Messenger;

/**
 * Model representing a Stripe Payout 'payout.paid' event for queued follow-up.
 */
class StripePayout
{
    public string $connectAccountId;
    public string $payoutId;

    public function getConnectAccountId(): string
    {
        return $this->connectAccountId;
    }

    public function setConnectAccountId(string $connectAccountId): self
    {
        $this->connectAccountId = $connectAccountId;

        return $this;
    }

    public function getPayoutId(): string
    {
        return $this->payoutId;
    }

    public function setPayoutId(string $payoutId): self
    {
        $this->payoutId = $payoutId;

        return $this;
    }
}
