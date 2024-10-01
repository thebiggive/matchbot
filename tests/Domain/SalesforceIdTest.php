<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Application\AssertionFailedException;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;

class SalesforceIdTest extends TestCase
{
    public function testAccepts18DigitSfID(): void
    {
        $sfId = Salesforce18Id::of('a01234567890123AAB');
        $this->assertSame('a01234567890123AAB', $sfId->value);
    }

    public function testRejectsTooShortSfID(): void
    {
        $this->expectException(AssertionFailedException::class);
        Salesforce18Id::of('a01234567890123AA');
    }

    public function testRejectsTooLongSfID(): void
    {
        $this->expectException(AssertionFailedException::class);
        Salesforce18Id::of('a01234567890123AABB');
    }

    public function testRejectsEmptySfID(): void
    {
        $this->expectException(AssertionFailedException::class);
        Salesforce18Id::of('');
    }

    public function testRejectsSfIDWithPunctuation(): void
    {
        $this->expectException(AssertionFailedException::class);
        Salesforce18Id::of('a0123456*890123AAB');
    }
}
