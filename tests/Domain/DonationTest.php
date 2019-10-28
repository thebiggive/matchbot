<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\Donation;

class DonationTest extends EntityTest
{
    public function testBasicsAsExpectedOnInstantion(): void
    {
        $donation = new Donation();

        $this->assertFalse($donation->isSuccessful());
        $this->assertEquals('not-sent', $donation->getSalesforcePushStatus());
        $this->assertNull($donation->getSalesforceLastPull());
        $this->assertNull($donation->getSalesforceLastPush());
        $this->assertNull($donation->getSalesforceId());
    }

    public function testValidDataPersisted(): void
    {
        $donation = new Donation();
        $donation->setAmount('100.00');
        $this->em->persist($donation);
        $this->em->flush();

        $this->addToAssertionCount(1); // Just check persist doesn't hit lifecycle hook exceptions
    }

    public function testAmountTooLowNotPersisted(): void
    {
        $donation = new Donation();
        $donation->setAmount('4.99');
        $this->em->persist($donation);
        $this->em->flush();

        $this->expectException(\UnexpectedValueException::class); // todo right exception
    }

    public function testAmountTooHighNotPersisted(): void
    {
        $donation = new Donation();
        $donation->setAmount('25000.01');
        $this->em->persist($donation);
        $this->em->flush();

        $this->expectException(\UnexpectedValueException::class); // todo right exception
    }
}
