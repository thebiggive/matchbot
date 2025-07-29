<?php

namespace MatchBot\Application\Messenger;

use Symfony\Component\Messenger\Bridge\AmazonSqs\MessageGroupAwareInterface;

/**
 * Model representing a Stripe Payout 'payout.paid' event for queued follow-up.
 */
readonly class StripePayout implements MessageGroupAwareInterface
{
    public function __construct(
        public string $connectAccountId,
        public string $payoutId,
    ) {
    }

    #[\Override]
    public function getMessageGroupId(): ?string
    {
        return 'payout.paid.' . $this->connectAccountId . '.' . $this->payoutId;
    }
}
