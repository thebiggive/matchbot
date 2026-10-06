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

    public function testItFindsNoParentOfLondon(): void
    {
        $location = UKLocation::findByCode('E12000007'); // London

        $this->assertSame($location->parentCode, null);
    }
}
