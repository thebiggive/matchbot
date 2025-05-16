<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\Country;
use MatchBot\Tests\TestCase;

class CountryTest extends TestCase
{
    /**
     * @dataProvider EUUKNONEUUKCountryCodesProvider
     */
    public function testItDetectsUKAndEUMembers(string $countryCode, bool $isUKOrEUMember): void
    {
        try {
            $country = Country::fromAlpha2($countryCode);
        } catch (\DomainException $exception) {
            if ($isUKOrEUMember) {
                throw $exception;
            }
            $country = null; // expected to have some unrecognised codes.
        }

        $this->assertTrue($country === null || $country->isEUOrUK() === $isUKOrEUMember);
    }

    /**
     * @return array<list{string, bool}>
     */
    public function EUUKNONEUUKCountryCodesProvider(): array
    {
        $allPossibleCodes = [];
        foreach (range('A', 'Z') as $first) {
            foreach (range('A', 'Z') as $second) {
                $code = $first . $second;
                $allPossibleCodes[$code] = $code;
            }
        }

        // List of countries as found at
        // https://ec.europa.eu/eurostat/statistics-explained/index.php?title=Glossary:Country_codes
        // with UK added since we charge for UK the same way as for EU.
        $EU_UK_COUNTRY_CODES = [
            'AT', // Austria
            'BE', // Belgium
            'BG', // Bulgaria
            'CY', // Cyprus
            'CZ', // Czechia
            'DE', // Germany
            'DK', // Denmark
            'EE', // Estonia
            'GR', // Greece - ISO code is GR, not EL as used within EU
            'ES', // Spain
            'FI', // Finland
            'FR', // France
            'GB', // UK
            'HR', // Croatia
            'HU', // Hungary
            'IE', // Ireland
            'IT', // Italy
            'LT', // Lithuania
            'LU', // Luxembourg
            'LV', // Latvia
            'MT', // Malta
            'NL', // Netherlands
            'PL', // Poland
            'PT', // Portugal
            'RO', // Romania
            'SE', // Sweden
            'SI', // Slovenia
            'SK', // Slovakia
        ];

        return array_map(
            static fn(string $code) => [$code, in_array($code, $EU_UK_COUNTRY_CODES, true)],
            $allPossibleCodes
        );
    }
}
