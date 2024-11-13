<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;
use PrinsFrank\Standards\Country\CountryAlpha2;

/**
 * A nation state, such as the UK. Currently used in our card fee calculation logic, may be used elsewhere in future.
 */
readonly class Country
{
    /** @var string[]   EU + GB ISO 3166-1 alpha-2 country codes */
    private const array EU_UK_COUNTRY_CODES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DK', 'EE',
        'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT',
        'LV', 'LT', 'LU', 'MT', 'NL', 'NO', 'PL',
        'PT', 'RO', 'RU', 'SI', 'SK', 'ES', 'SE',
        'CH', 'GB',
    ];

    private function __construct(
        public readonly CountryAlpha2 $alpha2
    ) {
    }

    /**
     * @param string $countryCode ISO 3166 two letter code
     */
    public static function fromAlpha2(string $countryCode): self
    {
        Assertion::regex($countryCode, '/^[A-Za-z]{2}$/');
        $alpha2 = CountryAlpha2::tryFrom(strtoupper($countryCode));

        if (! $alpha2) {
            throw new \DomainException("Unrecognised Country code: '$countryCode'");
        }

        return new self($alpha2);
    }

    public static function fromAlpha2OrNull(?string $countryCode): ?self
    {
        if ($countryCode === null) {
            return null;
        }

        return self::fromAlpha2($countryCode);
    }

    /**
     * @return bool True if is either the UK or any EU member
     */
    public function isEUOrUK(): bool
    {
        return in_array($this->alpha2->value, self::EU_UK_COUNTRY_CODES, true);
    }

    public function __toString(): string
    {
        return "{$this->alpha2->name} (code {$this->alpha2->value})";
    }
}
