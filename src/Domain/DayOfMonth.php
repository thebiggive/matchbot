<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assertion;

/**
 * A day number for a monthly event, between 1 and 28.
 * For simplicity of being able to have the same day every month we do not allow day numbers 29,30 or 31.
 */
#[Embeddable]
readonly class DayOfMonth
{
    private function __construct(
        #[Column(name: "dayOfMonth", type: 'smallint')]
        public int $value
    ) {
        Assertion::between($value, 1, 28);
    }

    public static function of(int $day): DayOfMonth
    {
        return new self($day);
    }
}
