<?php

declare(strict_types=1);

namespace MatchBot\Tests\Domain;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use UnexpectedValueException;

class DonationTest extends TestCase
{
    use DonationTestDataTrait;

    public function testBasicsAsExpectedOnInstantion(): void
    {
        $donation = $this->getMiminalDonation();

        $this->assertFalse($donation->getDonationStatus()->isSuccessful());
        $this->assertEquals('not-sent', $donation->getSalesforcePushStatus());
        $this->assertNull($donation->getSalesforceLastPush());
        $this->assertNull($donation->getSalesforceId());
        $this->assertNull($donation->hasGiftAid());
        $this->assertNull($donation->getCharityComms());
        $this->assertNull($donation->getTbgComms());
    }

    public function testPendingDonationDoesNotHavePostCreateUpdates(): void
    {
        $donation = $this->getMiminalDonation();
        $donation->setDonationStatus(DonationStatus::Pending);

        $this->assertFalse($donation->hasPostCreateUpdates());
    }

    public function testPaidDonationHasPostCreateUpdates(): void
    {
        $donation = $this->getMiminalDonation();
        $donation->setDonationStatus(DonationStatus::Paid);

        $this->assertTrue($donation->hasPostCreateUpdates());
    }

    public function testValidDataPersisted(): void
    {
        $donation = Donation::emptyTestDonation('100.00');
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

        Donation::emptyTestDonation('0.99');
    }

    public function testAmountTooHighNotPersisted(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Amount must be 1-25000 GBP');

        Donation::emptyTestDonation('25000.01');
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
            pspMethodType: PaymentMethodType::Card
        ), $this->getMinimalCampaign());
    }

    public function test200kCustomerBalanceDonationIsAllowed(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '200000',
            projectId: "any project",
            psp:'stripe',
            pspMethodType: PaymentMethodType::CustomerBalance
        ), $this->getMinimalCampaign());

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
            pspMethodType: PaymentMethodType::CustomerBalance
        ), $this->getMinimalCampaign());
    }

    public function testTipAmountTooHighNotPersisted(): void
    {
        $donation = $this->getMiminalDonation();

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
            pspMethodType: PaymentMethodType::CustomerBalance,
            tipAmount: '0.01',
        ), $this->getMinimalCampaign());
    }

    public function testInvalidPspRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Unexpected PSP 'paypal'");

        $donation = $this->getMiminalDonation();
        /** @psalm-suppress InvalidArgument */
        $donation->setPsp('paypal');
    }

    public function testValidPspAccepted(): void
    {
        $donation = $this->getMiminalDonation();
        $donation->setPsp('stripe');

        $this->addToAssertionCount(1); // Just check setPsp() doesn't hit an exception
    }

    public function testSetAndGetOriginalFee(): void
    {
        $donation = $this->getMiminalDonation();
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
        $this->assertNull($donationData['refundedTime']);
        $this->assertEquals('card', $donationData['pspMethodType']);
    }

    public function testToHookModelWhenRefunded(): void
    {
        $donation = $this->getTestDonation();
        $donation->recordRefundAt(new \DateTimeImmutable());

        $donationData = $donation->toHookModel();

        $this->assertIsString($donationData['collectedTime']);
        $this->assertIsString($donationData['refundedTime']);
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
            'automatic_payment_methods' => [
                'enabled' => true,
                'allow_redirects' => 'never',
            ],
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
        $donation = $this->getTestDonation(pspMethodType: PaymentMethodType::CustomerBalance, tipAmount: '0');

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

        $donation = $this->getTestDonation(pspMethodType: PaymentMethodType::CustomerBalance, tipAmount: '0', currencyCode: 'SEK');

        $donation->getStripeMethodProperties(); // Throws in this getter for now.
    }

    public function testDonationRefundDateTimeIsIncludedInSfHookModel(): void
    {
        $donation = $this->getTestDonation();

        $donation->recordRefundAt(new \DateTimeImmutable('2023-06-22 15:00'));

        $toHookModel = $donation->toHookModel();

        $this->assertSame(DonationStatus::Refunded, $toHookModel['status']);
        $this->assertSame('2023-06-22T15:00:00+00:00', $toHookModel['refundedTime']);
    }

    public function testMarkingRefundTwiceOnSameDonationDoesNotUpdateRefundTime(): void
    {
        $donation = $this->getTestDonation();

        $donation->recordRefundAt(new \DateTimeImmutable('2023-06-22 15:00'));
        $donation->recordRefundAt(new \DateTimeImmutable('2023-06-22 16:00'));

        $toHookModel = $donation->toHookModel();

        $this->assertSame(DonationStatus::Refunded, $toHookModel['status']);
        $this->assertSame('2023-06-22T15:00:00+00:00', $toHookModel['refundedTime']);
    }

    public function testCreateDonationModelWithDonorFields(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            firstName: 'Test First Name',
            lastName: 'Test Last Name',
            emailAddress: 'donor@email.test',
            currencyCode: 'GBP',
            donationAmount: '200000',
            projectId: "any project",
            psp:'stripe',
            pspMethodType: PaymentMethodType::CustomerBalance
        ), $this->getMinimalCampaign());

        $this->assertSame('Test First Name', $donation->getDonorFirstName(true));
        $this->assertSame('Test Last Name', $donation->getDonorLastName(true));
        $this->assertSame('donor@email.test', $donation->getDonorEmailAddress());
    }

    /**
     * @return array<array{0: ?string, 1: string}>
     */
    public function namesAndSFSafeNames()
    {
        return [
            ['Flintstone', 'Flintstone'],
            [null, 'N/A'],
            ['', 'N/A'],
            [' ', 'N/A'],
            ['王', '王'], // most common Chinese surname
            [str_repeat('王', 41), str_repeat('王', 40)],
            [str_repeat('a', 41), str_repeat('a', 40)],
            ['👍', '👍'],
            [str_repeat('👍', 41), str_repeat('👍', 40)],
            [str_repeat('👩‍👩‍👧‍👧', 10), '👩‍👩‍👧‍👧👩‍👩‍👧‍👧👩‍👩‍👧‍👧👩‍👩‍👧‍👧👩‍👩‍👧‍👧👩‍👩‍👧'],
            [str_repeat('👩‍👩‍👧‍👧', 41), '👩‍👩‍👧‍👧👩‍👩‍👧‍👧👩‍👩‍👧‍👧👩‍👩‍👧‍👧👩‍👩‍👧‍👧👩‍👩‍👧'],
            [str_repeat('👩‍👩‍👧‍👧', 401), '👩‍👩‍👧‍👧👩‍👩‍👧‍👧👩‍👩‍👧‍👧👩‍👩‍👧‍👧👩‍👩‍👧‍👧👩‍👩‍👧'],
        ];
    }

    /**
     * @dataProvider namesAndSFSafeNames
     */
    public function testItMakesDonorNameSafeForSalesforce(?string $originalName, string $expecteSafeName): void
    {
        $donation = $this->getMiminalDonation();
        $donation->setDonorLastName($originalName);

        $this->assertSame($expecteSafeName, $donation->getDonorLastName(true));
    }

    public function testCanCancelPendingDonation(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate('GBP', '1.00', 'project-id', 'stripe'), $this->getMinimalCampaign());
        $donation->cancel();

        $this->assertEquals(DonationStatus::Cancelled, $donation->getDonationStatus());
    }

    public function testCantCancelPaidDonation(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate('GBP', '1.00', 'project-id', 'stripe'), $this->getMinimalCampaign());
        $donation->setDonationStatus(DonationStatus::Paid);

        $this->expectExceptionMessage('Cannot cancel Paid donation');
        $donation->cancel();
    }

    public function testCannotCreateDonationWithNegativeTip(): void
    {
        $this->expectException(UnexpectedValueException::class);

        Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '10',
            projectId: 'project-id',
            psp: 'stripe',
            tipAmount: '-0.01'
        ), $this->getMinimalCampaign());
    }

    /**
     * @dataProvider APICountryCodeToModelCountryCode
     */
    public function testItTakesCountryCodeFromApiModel(?string $apiCountryCode, ?string $expected): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            countryCode: $apiCountryCode,
            currencyCode: 'GBP',
            donationAmount: '1.0',
            projectId: 'project_id',
            psp: 'stripe',
        ), new Campaign(TestCase::someCharity()));

        $this->assertSame($expected, $donation->getDonorCountryCode());
    }

    /**
     * @return array<array{0: ?string, 1: ?string}>
     */
    public function APICountryCodeToModelCountryCode(): array
    {
        return [
            ['', null],
            ['0', null],
            [null, null],
            ['BE', 'BE'],
            ['be', 'be']
        ];
    }

    /**
     * @dataProvider APIFeeCoverToModelFeeCover
     */
    public function testItTakesFeeCoverAmountFromApiModel(?string $feeCoverAmount, ?string $expected): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            feeCoverAmount: $feeCoverAmount,
            currencyCode: 'GBP',
            donationAmount: '1.0',
            projectId: 'project_id',
            psp: 'stripe',


        ), new Campaign(TestCase::someCharity()));

        $this->assertSame($expected, $donation->getFeeCoverAmount());
    }

    /**
     * @return array<array{0: ?string, 1: ?string}>
     */
    public function APIFeeCoverToModelFeeCover()
    {
        return [
            [null, '0.00'],
            ['', ''],
            ['3', '3'],
            ['3.123', '3.123'],
            ['non-numeric string', 'non-numeric string'],
        ];
    }

    public function testItThrowsIfAmountUpdatedByORM(): void
    {
        $donation = $this->getMiminalDonation();
        $this->expectExceptionMessage('Amount may not be changed after a donation is created');
        $changeset = [
            'amount' => ["1", "2"],
        ];
        $donation->preUpdate(new PreUpdateEventArgs($donation, $this->createStub(EntityManagerInterface::class), $changeset));
    }

    /**
     * @return Donation
     */
    public function getMiminalDonation(): Donation
    {
        return Donation::emptyTestDonation('1');
    }
}
