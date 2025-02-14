<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;

/**
 * @psalm-immutable
 */
enum Currency: string
{
    case GBP = 'GBP';
    case USD = 'USD';
    case CAD = 'CAD';
    case SEK = 'SEK';

    public static function fromIsoCode(mixed $isoCode): self
    {
        Assertion::length($isoCode, 3);
        Assertion::alnum($isoCode);

        // other currencies have some tests but are not fully supported
        if (! defined('RUNNING_UNIT_TESTS') && $isoCode !== 'GBP') {
            throw new \UnexpectedValueException("Unexpected Currency ISO Code " . $isoCode);
        }

        return self::tryFrom(strtoupper($isoCode)) ??
            throw new \UnexpectedValueException("Unexpected Currency ISO Code " . $isoCode);
    }

    /**
     * E.g. '£', '$' or '€'
     */
    public function symbol(): string
    {
        return match ($this) {
            self::GBP => '£',
            default => throw new \Exception("Unexpected currency " . $this->isoCode()),
        };
    }

    /**
     * @param 'upper'|'lower' $case
     * @return string
     */
    public function isoCode(string $case = 'upper'): string
    {
        return $case === 'upper' ? strtoupper($this->name) : strtolower($this->name);
    }
}
