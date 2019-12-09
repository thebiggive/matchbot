<?php

declare(strict_types=1);

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\Donation;
use PHPUnit\Framework\TestCase;

class DonationTest extends TestCase
{
    public function testBasicsAsExpectedOnInstantion(): void
    {
        $donation = new Donation();

        $this->assertFalse($donation->isSuccessful());
        $this->assertEquals('not-sent', $donation->getSalesforcePushStatus());
        $this->assertNull($donation->getSalesforceLastPush());
        $this->assertNull($donation->getSalesforceId());
    }

    public function testValidDataPersisted(): void
    {
        $donation = new Donation();
        $donation->setAmount('100.00');

        $this->addToAssertionCount(1); // Just check setAmount() doesn't hit an exception
    }

    public function testAmountTooLowNotPersisted(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Amount must be £5-25000');

        $donation = new Donation();
        $donation->setAmount('4.99');
    }

    public function testAmountTooHighNotPersisted(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Amount must be £5-25000');

        $donation = new Donation();
        $donation->setAmount('25000.01');
    }
}
