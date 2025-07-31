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
    case SEK = 'SEK';
    case EUR = 'EUR';

    public static function fromIsoCode(string $isoCode): self
    {
        Assertion::length($isoCode, 3);
        Assertion::alnum($isoCode);
        $isoCode = strtoupper($isoCode);

        // other currencies have some tests but are not fully supported. USD is also not fully tested but we have considered
        // USA a little more and have data relating to that in prod.
        if (! defined('RUNNING_UNIT_TESTS') && ! \in_array($isoCode, ['GBP', 'USD'], true)) {
            throw new \UnexpectedValueException("Unexpected Currency ISO Code " . $isoCode);
        }

        return self::tryFrom($isoCode) ??
            throw new \UnexpectedValueException("Unexpected Currency ISO Code " . $isoCode);
    }

    /**
     * E.g. '£', '$' or '€'
     */
    public function symbol(): string
    {
        return match ($this) {
            self::GBP => '£',
            self::USD => '$',
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
