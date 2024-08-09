<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;

enum Currency: string
{
    case GBP = 'GBP';

    public static function fromIsoCode(mixed $isoCode): self
    {
        Assertion::length($isoCode, 3);
        Assertion::alnum($isoCode);
        return match (strtoupper($isoCode)) {
            'GBP' => self::GBP,
            default => throw new \UnexpectedValueException("Unexpected Currency ISO Code" . $isoCode)
        };
    }

    /**
     * E.g. '£', '$' or '€'
     */
    public function symbol(): string
    {
        return match ($this) {
            self::GBP => '£',
        };
    }

    public function isoCode(): string
    {
        return $this->name;
    }
}
