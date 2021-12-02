<?php

declare(strict_types=1);

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\Donation;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;

class DonationTest extends TestCase
{
    use DonationTestDataTrait;

    public function testBasicsAsExpectedOnInstantion(): void
    {
        $donation = new Donation();

        $this->assertFalse($donation->isSuccessful());
        $this->assertEquals('not-sent', $donation->getSalesforcePushStatus());
        $this->assertNull($donation->getSalesforceLastPush());
        $this->assertNull($donation->getSalesforceId());
        $this->assertNull($donation->getClientSecret());
        $this->assertNull($donation->hasGiftAid());
        $this->assertNull($donation->getCharityComms());
        $this->assertNull($donation->getTbgComms());
    }

    public function testValidDataPersisted(): void
    {
        $donation = new Donation();
        $donation->setCurrencyCode('GBP');
        $donation->setAmount('100.00');
        $donation->setTipAmount('1.13');

        $this->assertEquals('100.00', $donation->getAmount());
        $this->assertEquals('1.13', $donation->getTipAmount());
        $this->assertEquals(113, $donation->getTipAmountFractional());
        $this->assertEquals(10_113, $donation->getAmountFractionalIncTip());
    }

    public function testAmountTooLowNotPersisted(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Amount must be 1-25000 GBP');

        $donation = new Donation();
        $donation->setCurrencyCode('GBP');
        $donation->setAmount('0.99');
    }

    public function testAmountTooHighNotPersisted(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Amount must be 1-25000 GBP');

        $donation = new Donation();
        $donation->setCurrencyCode('GBP');
        $donation->setAmount('25000.01');
    }

    public function testTipAmountTooHighNotPersisted(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Tip amount must not exceed 25000 GBP');

        $donation = new Donation();
        $donation->setCurrencyCode('GBP');
        $donation->setTipAmount('25000.01');
    }

    public function testInvalidPspRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Unexpected PSP 'paypal'");

        $donation = new Donation();
        $donation->setPsp('paypal');
    }

    public function testValidPspAccepted(): void
    {
        $donation = new Donation();
        $donation->setPsp('enthuse');

        $this->addToAssertionCount(1); // Just check setPsp() doesn't hit an exception
    }

    public function testSetAndGetOriginalFee(): void
    {
        $donation = new Donation();
        $donation->setOriginalPspFeeFractional(123);

        $this->assertEquals('1.23', $donation->getOriginalPspFee());
    }

    public function testToClaimBotModel(): void
    {
        $donation = $this->getTestDonation();

        $donation->getCampaign()->getCharity()->setTbgClaimingGiftAid(true);
        $donation->getCampaign()->getCharity()->setHmrcReferenceNumber('AB12345');
        $donation->setTbgShouldProcessGiftAid(true);

        $claimBotMessage = $donation->toClaimBotModel();

        $nowInYmd = date('Y-m-d');
        $this->assertEquals('12345678-1234-1234-1234-1234567890ab', $claimBotMessage->id); // UUID
        // collectedAt is set by `setDonationStatus('Collected')`
        $this->assertEquals($nowInYmd, $claimBotMessage->donation_date);
        $this->assertEquals('', $claimBotMessage->title);
        $this->assertEquals('John', $claimBotMessage->first_name);
        $this->assertEquals('Doe', $claimBotMessage->last_name);
        $this->assertEquals('1', $claimBotMessage->house_no);
        $this->assertEquals('N1 1AA', $claimBotMessage->postcode);
        $this->assertEquals(123.45, $claimBotMessage->amount);
        $this->assertEquals('AB12345', $claimBotMessage->org_hmrc_ref);
    }
}
