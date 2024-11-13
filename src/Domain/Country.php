<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;
use PrinsFrank\Standards\Country\CountryAlpha2;
use PrinsFrank\Standards\Country\Groups\EU;

/**
 * A nation state, such as the UK. Currently used in our card fee calculation logic, may be used elsewhere in future.
 */
readonly class Country
{
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
        // We may want to use EEA later, but need to clarify our Stripe fee schedule first.
        return $this->alpha2->isMemberOf(EU::class) || $this->alpha2->value === 'GB';
    }

    public function __toString(): string
    {
        return "{$this->alpha2->name} (code {$this->alpha2->value})";
    }
}
