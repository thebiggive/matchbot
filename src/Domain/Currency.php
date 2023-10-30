<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;

enum Currency
{
    case GBP;

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

    /**
     * @return string 3 Letter upper case ISO code, e.g. 'GBP'
     */
    public function isoCode(): string{
        return match ($this) {
            self::GBP => 'GBP',
        };
    }
}
