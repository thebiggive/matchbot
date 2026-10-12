<?php

namespace MatchBot\Domain;

use PHPUnit\Framework\TestCase;

class UKLocationTest extends TestCase
{
    public function testItHasHundredsOfLocations(): void
    {
        self::assertCount(394, UKLocation::LOCATIONS);
    }

    public function testItFindsLondonAsParentOfHaringey(): void
    {
        $location = UKLocation::findByCode('E09000014'); // Haringey
        self::assertSame('E09000014', $location->code);

        $this->assertSame($location->parentCode, 'E12000007'); // London
    }

    public function testItFindsOtherBoroughsAsSiblingsOfHaringey(): void
    {
        $location = UKLocation::findByCode('E09000014'); // Haringey

        $siblingNames = \array_map(
            fn(string $code) => UKLocation::findByCode($code)->name,
            $location->siblingCodes
        );

        self::assertSame(
            [
                'City of London',
                'Barking and Dagenham',
                'Barnet',
                'Bexley',
                'Brent',
                'Bromley',
                'Camden',
                'Croydon',
                'Ealing',
                'Enfield',
                'Greenwich',
                'Hackney',
                'Hammersmith and Fulham',
                //    'Haringey', not counting the place itself as its own sibling
                'Harrow',
                'Havering',
                'Hillingdon',
                'Hounslow',
                'Islington',
                'Kensington and Chelsea',
                'Kingston upon Thames',
                'Lambeth',
                'Lewisham',
                'Merton',
                'Newham',
                'Redbridge',
                'Richmond upon Thames',
                'Southwark',
                'Sutton',
                'Tower Hamlets',
                'Waltham Forest',
                'Wandsworth',
                'Westminster',
            ],
            $siblingNames
        );
    }

    public function testItFindsChildrenOfLondon(): void
    {
        $location = UKLocation::findByCode('E12000007'); // London

        $childNames = \array_map(
            fn(string $code) => UKLocation::findByCode($code)->name,
            $location->childCodes
        );

        self::assertSame(
            [
                'City of London',
                'Barking and Dagenham',
                'Barnet',
                'Bexley',
                'Brent',
                'Bromley',
                'Camden',
                'Croydon',
                'Ealing',
                'Enfield',
                'Greenwich',
                'Hackney',
                'Hammersmith and Fulham',
                'Haringey',
                'Harrow',
                'Havering',
                'Hillingdon',
                'Hounslow',
                'Islington',
                'Kensington and Chelsea',
                'Kingston upon Thames',
                'Lambeth',
                'Lewisham',
                'Merton',
                'Newham',
                'Redbridge',
                'Richmond upon Thames',
                'Southwark',
                'Sutton',
                'Tower Hamlets',
                'Waltham Forest',
                'Wandsworth',
                'Westminster',
            ],
            $childNames
        );
    }

    /**
     * We think when a user searches for a given region then we need to show not just campaigns that are directly linked
     * that region but also any linked to the regions within it, which in the case of East of England are at three levels
     * (e.g. East of England, Cambridgeshire, Huntingdonshire). Afaik none go deeper.
     */
    public function testItFindsAllDescendantsOfARegion(): void
    {
        $location = UKLocation::findByCode('E12000006'); // East of England

        $allDescendantRegions = UKLocation::allDescendantsOf($location);

        self::assertEqualsCanonicalizing(
            [
                'Babergh',
                'Castle Point',
                'Central Bedfordshire',
                'Chelmsford',
                'Colchester',
                'Dacorum',
                'East Cambridgeshire',
                'East Hertfordshire',
                'East Suffolk',
                'Epping Forest',
                'Essex',
                'Fenland',
                'Great Yarmouth',
                'Harlow',
                'Hertfordshire',
                'Hertsmere',
                'Huntingdonshire',
                'Ipswich',
                'King\'s Lynn and West Norfolk',
                'Luton',
                'Maldon',
                'Mid Suffolk',
                'Norfolk',
                'North Hertfordshire',
                'North Norfolk',
                'Norwich',
                'Peterborough',
                'Rochford',
                'South Cambridgeshire',
                'South Norfolk',
                'Southend-on-Sea',
                'St Albans',
                'Stevenage',
                'Suffolk',
                'Tendring',
                'Three Rivers',
                'Thurrock',
                'Uttlesford',
                'Watford',
                'Welwyn Hatfield',
                'West Suffolk',
                'Basildon',
                'Bedford',
                'Braintree',
                'Breckland',
                'Brentwood',
                'Broadland',
                'Broxbourne',
                'Cambridge',
                'Cambridgeshire',
            ],
            \array_map(fn($l) => $l->name, $allDescendantRegions)
        );
    }

    public function testItFindsNoParentOfLondon(): void
    {
        $location = UKLocation::findByCode('E12000007'); // London

        $this->assertSame($location->parentCode, null);
    }
}
