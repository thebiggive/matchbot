<?php

declare(strict_types=1);

namespace MatchBot\Tests\Domain;

use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;

class DonationTest extends TestCase
{
    use DonationTestDataTrait;

    public function testBasicsAsExpectedOnInstantion(): void
    {
        $donation = Donation::emptyTestDonation('1');

        $this->assertFalse($donation->getDonationStatus()->isSuccessful());
        $this->assertEquals('not-sent', $donation->getSalesforcePushStatus());
        $this->assertNull($donation->getSalesforceLastPush());
        $this->assertNull($donation->getSalesforceId());
        $this->assertNull($donation->getClientSecret());
        $this->assertNull($donation->hasGiftAid());
        $this->assertNull($donation->getCharityComms());
        $this->assertNull($donation->getTbgComms());
    }

    public function testPendingDonationDoesNotHavePostCreateUpdates(): void
    {
        $donation = Donation::emptyTestDonation('1');
        $donation->setDonationStatus(DonationStatus::Pending);

        $this->assertFalse($donation->hasPostCreateUpdates());
    }

    public function testPaidDonationHasPostCreateUpdates(): void
    {
        $donation = Donation::emptyTestDonation('1');
        $donation->setDonationStatus(DonationStatus::Paid);

        $this->assertTrue($donation->hasPostCreateUpdates());
    }

    public function testValidDataPersisted(): void
    {
        $donation = Donation::emptyTestDonation('100.00');
        $donation->setCurrencyCode('GBP');
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

        $donation = Donation::emptyTestDonation('0.99');
        $donation->setCurrencyCode('GBP');
    }

    public function testAmountTooHighNotPersisted(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Amount must be 1-25000 GBP');

        $donation = Donation::emptyTestDonation('25000.01');
        $donation->setCurrencyCode('GBP');
    }

    public function test25k1CardIsTooHigh(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Amount must be 1-25000 GBP');

        Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '25001',
            projectId: "any project",
            psp:'stripe',
            paymentMethodType: PaymentMethodType::Card
        ), new Campaign());
    }

    public function test200kCustomerBalanceDonationIsAllowed(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '200000',
            projectId: "any project",
            psp:'stripe',
            paymentMethodType: PaymentMethodType::CustomerBalance
        ), new Campaign());

        $this->assertSame('200000', $donation->getAmount());
    }

    public function test200k1CustomerBalanceDonationIsTooHigh(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Amount must be 1-200000 GBP');

        Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '200001',
            projectId: "any project",
            psp:'stripe',
            paymentMethodType: PaymentMethodType::CustomerBalance
        ), new Campaign());
    }

    public function testTipAmountTooHighNotPersisted(): void
    {
        $donation = Donation::emptyTestDonation('1');
        $donation->setCurrencyCode('GBP');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Tip amount must not exceed 25000 GBP');

        $donation->setTipAmount('25000.01');
    }

    public function testCustomerBalananceDonationsDoNotAcceptTips(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('A Customer Balance Donation may not include a tip');

        Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '1',
            projectId: "any project",
            psp:'stripe',
            paymentMethodType: PaymentMethodType::CustomerBalance,
            tipAmount: '0.01',
        ), new Campaign());
    }

    public function testInvalidPspRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Unexpected PSP 'paypal'");

        $donation = Donation::emptyTestDonation('1');
        /** @psalm-suppress InvalidArgument */
        $donation->setPsp('paypal');
    }

    public function testValidPspAccepted(): void
    {
        $donation = Donation::emptyTestDonation('1');
        $donation->setPsp('stripe');

        $this->addToAssertionCount(1); // Just check setPsp() doesn't hit an exception
    }

    public function testSetAndGetOriginalFee(): void
    {
        $donation = Donation::emptyTestDonation('1');
        $donation->setOriginalPspFeeFractional(123);

        $this->assertEquals('1.23', $donation->getOriginalPspFee());
    }

    public function testToApiModel(): void
    {
        $fundingWithdrawal = new FundingWithdrawal();
        $fundingWithdrawal->setAmount('1.23');
        $donation = $this->getTestDonation();
        $donation->addFundingWithdrawal($fundingWithdrawal);

        $donationData = $donation->toApiModel();

        $this->assertEquals('john.doe@example.com', $donationData['emailAddress']);
        $this->assertEquals('1.23', $donationData['matchedAmount']);
        $this->assertIsString($donationData['collectedTime']);
    }

    public function testToApiModelTemporaryHackHasNoImpact(): void
    {
        $donation = $this->getTestDonation();
        $donation->setDonorEmailAddress('noel;;@thebiggive.org.uk');

        $donationData = $donation->toApiModel();

        $this->assertEquals('noel;;@thebiggive.org.uk', $donationData['emailAddress']);
    }

    public function testToHookModel(): void
    {
        $donation = $this->getTestDonation();

        $donationData = $donation->toHookModel();

        $this->assertEquals('john.doe@example.com', $donationData['emailAddress']);
        $this->assertIsString($donationData['collectedTime']);
        $this->assertEquals('card', $donationData['pspMethodType']);
    }

    public function testToHookModelTemporaryHack(): void
    {
        $donation = $this->getTestDonation();
        $donation->setDonorEmailAddress('noel;;@thebiggive.org.uk');

        $donationData = $donation->toHookModel();

        $this->assertEquals('noel@thebiggive.org.uk', $donationData['emailAddress']);
    }

    public function testToClaimBotModelUK(): void
    {
        $donation = $this->getTestDonation();

        $donation->getCampaign()->getCharity()->setTbgClaimingGiftAid(true);
        $donation->getCampaign()->getCharity()->setHmrcReferenceNumber('AB12345');
        $donation->getCampaign()->getCharity()->setRegulator('CCEW');
        $donation->getCampaign()->getCharity()->setRegulatorNumber('12229');
        $donation->setTbgShouldProcessGiftAid(true);

        $claimBotMessage = $donation->toClaimBotModel();

        $nowInYmd = date('Y-m-d');
        $this->assertEquals('12345678-1234-1234-1234-1234567890ab', $claimBotMessage->id); // UUID
        $this->assertEquals($nowInYmd, $claimBotMessage->donation_date);
        $this->assertEquals('', $claimBotMessage->title);
        $this->assertEquals('John', $claimBotMessage->first_name);
        $this->assertEquals('Doe', $claimBotMessage->last_name);
        $this->assertEquals('1', $claimBotMessage->house_no);
        $this->assertEquals('N1 1AA', $claimBotMessage->postcode);
        $this->assertEquals(123.45, $claimBotMessage->amount);
        $this->assertEquals('AB12345', $claimBotMessage->org_hmrc_ref);
        $this->assertEquals('CCEW', $claimBotMessage->org_regulator);
        $this->assertEquals('12229', $claimBotMessage->org_regulator_number);
        $this->assertEquals('Test charity', $claimBotMessage->org_name);
        $this->assertEquals(false, $claimBotMessage->overseas);
    }

    public function testToClaimBotModelOverseas(): void
    {
        $donation = $this->getTestDonation();

        $donation->setDonorHomePostcode('OVERSEAS');
        $donation->getCampaign()->getCharity()->setTbgClaimingGiftAid(true);
        $donation->getCampaign()->getCharity()->setHmrcReferenceNumber('AB12345');
        $donation->getCampaign()->getCharity()->setRegulator(null); // e.g. Exempt.
        $donation->getCampaign()->getCharity()->setRegulatorNumber('12222');
        $donation->setTbgShouldProcessGiftAid(true);

        $claimBotMessage = $donation->toClaimBotModel();

        $this->assertEquals('1 Main St, London', $claimBotMessage->house_no);
        $this->assertEquals('', $claimBotMessage->postcode);
        $this->assertEquals(true, $claimBotMessage->overseas);
        $this->assertNull($claimBotMessage->org_regulator);
        $this->assertEquals('12222', $claimBotMessage->org_regulator_number);
    }

    public function testGetStripePIHelpersWithCard(): void
    {
        $donation = $this->getTestDonation();

        $expectedPaymentMethodProperties = [
            'payment_method_types' => ['card'],
        ];

        $expectedOnBehalfOfProperties = [
            'on_behalf_of' => 'unitTest_stripeAccount_123',
        ];

        $this->assertEquals($expectedPaymentMethodProperties, $donation->getStripeMethodProperties());
        $this->assertEquals($expectedOnBehalfOfProperties, $donation->getStripeOnBehalfOfProperties());
        $this->assertTrue($donation->supportsSavingPaymentMethod());
    }

    public function testGetStripePIHelpersWithCustomerBalanceGbp(): void
    {
        $donation = $this->getTestDonation(paymentMethodType: PaymentMethodType::CustomerBalance, tipAmount: '0');
        $donation->setCurrencyCode('GBP');

        $expectedPaymentMethodProperties = [
            'payment_method_types' => ['customer_balance'],
            'payment_method_data' => [
                'type' => 'customer_balance',
            ],
            'payment_method_options' => [
                'customer_balance' => [
                    'funding_type' => 'bank_transfer',
                    'bank_transfer' => [
                        'type' => 'gb_bank_transfer',
                    ],
                ],
            ],
        ];

        $this->assertEquals($expectedPaymentMethodProperties, $donation->getStripeMethodProperties());
        $this->assertEquals([], $donation->getStripeOnBehalfOfProperties());
        $this->assertFalse($donation->supportsSavingPaymentMethod());
    }

    public function testGetStripeMethodPropertiesCustomerBalanceUsd(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Customer balance payments only supported for GBP');

        $donation = $this->getTestDonation(paymentMethodType: PaymentMethodType::CustomerBalance, tipAmount: '0');
        $donation->setCurrencyCode('USD');

        $donation->getStripeMethodProperties(); // Throws in this getter for now.
    }
}
