<?php

use MatchBot\Domain\Donation;
use PHPUnit\Framework\TestCase;

class DonationTest extends TestCase
{
    public function testBasicsAsExpectedOnInstantion()
    {
        $donation = new Donation();

        $this->assertFalse($donation->isSuccessful());
        $this->assertEquals('not-sent', $donation->getSalesforcePushStatus());
        $this->assertNull($donation->getSalesforceLastPull());
        $this->assertNull($donation->getSalesforceLastPush());
        $this->assertNull($donation->getSalesforceId());
    }
}
