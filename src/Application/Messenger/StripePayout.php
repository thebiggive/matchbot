<?php

namespace MatchBot\Application\Messenger;

/**
 * Model representing a Stripe Payout 'payout.paid' event for queued follow-up.
 */
readonly class StripePayout
{
    public function __construct(
        public string $connectAccountId,
        public string $payoutId,
    )
    {
    }
}
