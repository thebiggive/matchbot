<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assertion;

/**
 * A day number for a monthly event, between 1 and 31.
 */
#[Embeddable]
readonly class DayOfMonth
{
    private function __construct(
        #[Column(name: "dayOfMonth", type: 'smallint')]
        public int $value
    ) {
        Assertion::between($value, 1, 31);
    }

    public static function of(int $day): DayOfMonth
    {
        return new self($day);
    }
}
