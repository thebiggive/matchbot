<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\PersonId;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

class PersonIdTest extends TestCase
{
    public function testTwoIdsFromSameStringAreEqual(): void
    {
        $anyUUID = 'b13e4c9c-229a-11f0-bf58-1f1f1db0aefb';

        self::assertTrue(PersonId::of($anyUUID)->equals(PersonId::of($anyUUID)));
    }

    public function testIsEqualToItself(): void
    {
        $personId = PersonId::of(Uuid::uuid4()->toString());
        $itSelf = $personId;

        self::assertTrue($personId->equals($itSelf));
    }

    public function testTwoIdsFromDifferentStringAreNotEqual(): void
    {
        $id1 = PersonId::of(Uuid::uuid4()->toString());
        $id2 = PersonId::of(Uuid::uuid4()->toString());

        self::assertFalse($id1->equals($id2));
    }

    public function testRoundTripFromUUID(): void
    {
        $uuid = Uuid::uuid4();
        $this->assertTrue(PersonId::of($uuid->toString())->id->equals($uuid));
    }
}
