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
        // Invoking real Doctrine events but with a fake driver, for isolated unit testing, is
        // both complex and not very valuable, so for now we just manually pretend the object is
        // about to be persisted rather than bootstrapping a whole fake EntityManager.
        $donation->prePersist();

        $this->addToAssertionCount(1); // Just check persist doesn't hit lifecycle hook exceptions
    }

    public function testAmountTooLowNotPersisted(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Amount must be £5-25000');

        $donation = new Donation();
        $donation->setAmount('4.99');
        $donation->prePersist();
    }

    public function testAmountTooHighNotPersisted(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Amount must be £5-25000');

        $donation = new Donation();
        $donation->setAmount('25000.01');
        $donation->prePersist();
    }
}
