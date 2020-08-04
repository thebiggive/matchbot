<?php

declare(strict_types=1);

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\Donation;
use MatchBot\Tests\TestCase;

class DonationTest extends TestCase
{
    public function testBasicsAsExpectedOnInstantion(): void
    {
        $donation = new Donation();

        $this->assertFalse($donation->isSuccessful());
        $this->assertEquals('not-sent', $donation->getSalesforcePushStatus());
        $this->assertNull($donation->getSalesforceLastPush());
        $this->assertNull($donation->getSalesforceId());
        $this->assertNull($donation->getClientSecret());
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
        $this->expectExceptionMessage('Amount must be £1-25000');

        $donation = new Donation();
        $donation->setAmount('0.99');
    }

    public function testAmountTooHighNotPersisted(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Amount must be £1-25000');

        $donation = new Donation();
        $donation->setAmount('25000.01');
    }

    public function testInvalidPspRejected()
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Unexpected PSP 'paypal'");

        $donation = new Donation();
        $donation->setPsp('paypal');
    }

    public function testValidPspAccepted()
    {
        $donation = new Donation();
        $donation->setPsp('enthuse');

        $this->addToAssertionCount(1); // Just check setPsp() doesn't hit an exception
    }

    public function testAmountForCharityWithTip()
    {
        // N.B. tip to TBG should not change the amount the charity receives, and the tip
        // is not included in the core donation amount set by `setAmount()`.
        $donation = new Donation();
        $donation->setAmount('987.65');
        $donation->setTipAmount('10.00');

        // £987.65 * 1.2%   = £ 11.85 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee        = £ 12.05
        // Amount after fee = £975.60

        $this->assertEquals('975.60', $donation->getAmountForCharity());
    }

    public function testAmountForCharityWithoutTip()
    {
        $donation = new Donation();
        $donation->setAmount('987.65');

        // £987.65 * 1.2%   = £ 11.85 (to 2 d.p.)
        // Fixed fee        = £  0.20
        // Total fee        = £ 12.05
        // Amount after fee = £975.60

        $this->assertEquals('975.60', $donation->getAmountForCharity());
    }

    public function testAmountForCharityWithoutTipRoundingOnPointFive()
    {
        $donation = new Donation();
        $donation->setAmount('6.25');

        // £1.25 * 1.2% = £ 0.08 (to 2 d.p. – following normal mathematical rounding from £0.075)
        // Fixed fee    = £ 0.20
        // Total fee    = £ 0.28
        // After fee    = £ 5.97
        $this->assertEquals('5.97', $donation->getAmountForCharity());
    }
}
