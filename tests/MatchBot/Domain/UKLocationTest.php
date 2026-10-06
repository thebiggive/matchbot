<?php

namespace MatchBot\Domain;

use PHPUnit\Framework\TestCase;

class UKLocationTest extends TestCase
{
    public function testItHasHundredsOfLocations(): void
    {
        self::assertCount(394, UKLocation::LOCATIONS);
    }
}
