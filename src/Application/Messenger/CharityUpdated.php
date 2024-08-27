<?php

namespace MatchBot\Application\Messenger;

use MatchBot\Domain\Charity;
use MatchBot\Domain\Salesforce18Id;

/**
 * Message dispatched when Salesforce tells us that something material to donation processing or
 * Gift Aid changed about the {@see Charity}.
 */
readonly class CharityUpdated
{
    public function __construct(public Salesforce18Id $charityAccountId)
    {
    }
}
