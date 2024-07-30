<?php

declare(strict_types=1);

namespace MatchBot\Application\Matching;

use JetBrains\PhpStorm\Pure;

class LessThanRequestedAllocatedException extends \Exception
{
    /**
     * @param string $amountAllocated       May be zero if somebody else just secured the last funds.
     */
    #[Pure]
    public function __construct(
        private string $amountAllocated
    ) {
        parent::__construct('Less than requested was allocated');
    }

    public function getAmountAllocated(): string
    {
        return $this->amountAllocated;
    }
}
