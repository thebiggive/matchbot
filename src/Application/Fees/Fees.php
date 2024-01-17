<?php

namespace MatchBot\Application\Fees;

use MatchBot\Application\Assertion;

class Fees
{
    public function __construct(public readonly string $coreFee, public readonly string $feeVat)
    {
        Assertion::numeric($this->coreFee);
        Assertion::numeric($this->feeVat);

        Assertion::allGreaterOrEqualThan([$this->coreFee, $this->feeVat], 0);
    }
}
