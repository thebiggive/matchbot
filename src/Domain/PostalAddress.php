<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assertion;

/**
 * Value object for convenience and organisation but with very minimal validation since data is
 * currently all entered via Salesforce so we can't throw back to the UI for any bad data.
 *
 * Allows all properties to be null as I think Doctrine won't allow the entire object to be replaced with null
 */
#[Embeddable]
readonly class PostalAddress
{
    #[Column(nullable: true)] public ?string $line1;
    #[Column(nullable: true)] public ?string $line2;
    #[Column(nullable: true)] public ?string $city;
    #[Column(nullable: true)] public ?string $postalCode;
    #[Column(nullable: true)] public ?string $country;

    public static function of(
        string $line1,
        ?string $line2,
        ?string $city,
        ?string $postalCode,
        ?string $country,
    ): self {
        return new self(
            line1: $line1,
            line2: $line2,
            city: $city,
            postalCode: $postalCode,
            country: $country
        );
    }

    private function __construct(
        ?string $line1,
        ?string $line2,
        ?string $city,
        ?string $postalCode,
        ?string $country,
    ) {
        $this->country = $country;
        $this->postalCode = $postalCode;
        $this->city = $city;
        $this->line2 = $line2;
        $this->line1 = $line1;

        Assertion::nullOrbetweenLength($line1, 1, 255);
        Assertion::nullOrbetweenLength($line2, 1, 255);
        Assertion::nullOrbetweenLength($city, 1, 255);
        Assertion::nullOrbetweenLength($country, 1, 255);
        Assertion::nullOrbetweenLength($postalCode, 1, 255);
    }

    /**
     * For now this follows the implementation in Salesforce - preserving any line breaks present inside the sections
     * but not adding them between. After the SF code is retired we might want to use line breaks between the sections.
     */
    public function format(): ?string
    {
        $nonBlankSections = array_filter(
            [$this->line1, $this->line2, $this->city, $this->postalCode, $this->country],
            static fn($line) => \is_string($line) && $line !== ''
        );

        if ($nonBlankSections === []) {
            return null;
        }

        return implode(", ", $nonBlankSections);
    }

    /**
     * Used in place of null to work around ORM / relational DB limitations.
     */
    public static function null(): self
    {
        return new self(line1: null, line2: null, city: null, postalCode: null, country: null);
    }
}
