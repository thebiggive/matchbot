<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assertion;

/**
 * Value object for convenience and organisation but with very minimal validation since data is
 * currently all entered via Salesforce so we can't throw back to the UI for any bad data.
 */
#[Embeddable]
readonly class PostalAddress
{
    public static function of(
        string $line1,
        ?string $line2,
        ?string $city,
        ?string $postalCode,
        ?string $country,
    ): self {
        return new self($line1, $line2, $city, $postalCode, $country);
    }

    private function __construct(
        #[Column(nullable: true)]
        public string $line1,

        #[Column(nullable: true)]
        public ?string $line2,

        #[Column(nullable: true)]
        public ?string $city,

        #[Column(nullable: true)]
        public ?string $postalCode,

        #[Column(nullable: true)]
        public ?string $country,
    ) {
        Assertion::betweenLength($line1, 1, 255);
        Assertion::nullOrbetweenLength($line2, 1, 255);
        Assertion::nullOrbetweenLength($city, 1, 255);
        Assertion::nullOrbetweenLength($country, 1, 255);
        Assertion::nullOrbetweenLength($postalCode, 1, 255);
    }

    /**
     * For now this follows the implementation in Salesforce - preserving any line breaks present inside the sections
     * but not adding them between. After the SF code is retired we might want to use line breaks between the sections.
     */
    public function format(): string
    {
        $nonBlankSections = array_filter(
            [$this->line1, $this->line2, $this->city, $this->postalCode, $this->country]
        );

        return implode(", ", $nonBlankSections);
    }
}
