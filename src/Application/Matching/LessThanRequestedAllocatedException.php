<?php

declare(strict_types=1);

namespace MatchBot\Application\Matching;

use JetBrains\PhpStorm\Pure;

class LessThanRequestedAllocatedException extends \Exception
{
    /**
     * @param string $amountAllocated       May be zero if somebody else just secured the last funds.
     * @param string $fundRemainingAmount   Typically zero, *unless* the adapter got stuck in a race condition loop with
     *                                      another thread and reached its maximum tries. If this rare edge case happens
     *                                      it might decide to bail out leaving a little in the match pot. The donor
     *                                      will be told how much was reserved for them if this happens and given the
     *                                      option to proceed or cancel the reservation.
     */
    #[Pure]
    public function __construct(
        private string $amountAllocated,
        private string $fundRemainingAmount
    ) {
        parent::__construct('Less than requested was allocated');
    }

    public function getAmountAllocated(): string
    {
        return $this->amountAllocated;
    }
}
