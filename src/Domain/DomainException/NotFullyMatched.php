<?php

namespace MatchBot\Domain\DomainException;

use MatchBot\Domain\Money;

/**
 * Thrown to stop a donation being set up without matching when a full match was expected.
 */
class NotFullyMatched extends DomainException
{
    /**
     * @param Money $maxMatchable The maximum amount that we would be able to match per donation, given match funds
     * available
     */
    public function __construct(string $message, public readonly Money $maxMatchable)
    {
        parent::__construct($message);
    }
}
