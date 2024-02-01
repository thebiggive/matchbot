<?php

declare(strict_types=1);

namespace MatchBot\Tests\Domain;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use MatchBot\Application\AssertionFailedException;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\ChampionFund;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\Pledge;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use UnexpectedValueException;

class DonationTest extends TestCase
{
    use DonationTestDataTrait;

    public function testBasicsAsExpectedOnInstantion(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '1',
            projectId: "any project",
            psp:'stripe',
            pspMethodType: PaymentMethodType::Card
        ), $this->getMinimalCampaign());

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
        $donation = $this->getTestDonation();
        $donation->setDonationStatus(DonationStatus::Pending);

        $this->assertFalse($donation->hasPostCreateUpdates());
    }

    public function testPaidDonationHasPostCreateUpdates(): void
    {
        $donation = $this->getTestDonation();
        $donation->setDonationStatus(DonationStatus::Paid);

        $this->assertTrue($donation->hasPostCreateUpdates());
    }

    public function testValidDataPersisted(): void
    {
        $donation = $this->getTestDonation('100.00');
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

        $this->getTestDonation('0.99');
    }

    public function testAmountVerySlightlyTooLowNotPersisted(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Amount must be 1-25000 GBP');

        // PHP floating point math doesn't distinguish between this and 1, but as we use BC Math we can reject it as
        // too small:
        // See https://3v4l.org/#live
        $justLessThanOne = '0.99999999999999999';
        $this->getTestDonation($justLessThanOne);
    }

    public function testAmountTooHighNotPersisted(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Amount must be 1-25000 GBP');

        $this->getTestDonation('25000.01');
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
        $donation = $this->getTestDonation();

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
        $this->expectException(AssertionFailedException::class);
        $this->expectExceptionMessage('Value "paypal" does not equal expected value "stripe".');

        /** @psalm-suppress InvalidArgument */
        Donation::fromApiModel(
            new DonationCreate(
                currencyCode: 'GBP',
                donationAmount: '63.0',
                projectId: 'doesnt-matter',
                psp: 'paypal',
            ),
            new Campaign(TestCase::someCharity())
        );
    }

    public function testValidPspAccepted(): void
    {
        $_donation = $this->getTestDonation();

        $this->addToAssertionCount(1); // Just check setPsp() doesn't hit an exception
    }

    public function testSetAndGetOriginalFee(): void
    {
        $donation = $this->getTestDonation();
        $donation->setOriginalPspFeeFractional('123');

        $this->assertEquals('1.23', $donation->getOriginalPspFee());
    }

    public function testToApiModel(): void
    {
        $campaignFunding = new CampaignFunding();
        $campaignFunding->setCurrencyCode('GBP');
        $campaignFunding->setAmountAvailable('1.23');

        $fundingWithdrawal = new FundingWithdrawal($campaignFunding);
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
        $donation = $this->getTestDonation(
            tbgGiftAidRequestConfirmedCompleteAt: new DateTime('2000-01-01T00:00:00+00:00')
        );

        $donationData = $donation->toHookModel();

        $this->assertEquals('john.doe@example.com', $donationData['emailAddress']);
        $this->assertIsString($donationData['collectedTime']);
        $this->assertNull($donationData['refundedTime']);
        $this->assertEquals('card', $donationData['pspMethodType']);
        $this->assertEquals('2000-01-01T00:00:00+00:00', $donationData['tbgGiftAidRequestConfirmedCompleteAt']);
    }

    public function testAmountMatchedByChampionDefaultsToZero(): void
    {
        $donation = $this->getTestDonation();

        $amountMatchedByChampionFunds = $donation->toHookModel()['amountMatchedByChampionFunds'];

        $this->assertSame(0.0, $amountMatchedByChampionFunds);
    }

    public function testItSumsNoChampionFundsToZero(): void
    {
        $donation = $this->getTestDonation();

        $amountMatchedByPledges = $donation->toHookModel()['amountMatchedByChampionFunds'];

        $this->assertSame(0.0, $amountMatchedByPledges);
    }

    public function testItSumsAmountsMatchedByChampionFunds(): void
    {
        $donation = $this->getTestDonation();

        $campaignFunding = new CampaignFunding();
        $campaignFunding->setFund(new ChampionFund());
        $withdrawal0 = new FundingWithdrawal($campaignFunding);
        $withdrawal0->setAmount('1');

        $withdrawal1 = clone $withdrawal0;
        $withdrawal1->setAmount('2');


        $donation->addFundingWithdrawal($withdrawal0);
        $donation->addFundingWithdrawal($withdrawal1);

        $amountMatchedByPledges = $donation->toHookModel()['amountMatchedByChampionFunds'];

        \assert(1 + 2 === 3);
        $this->assertSame(3.0, $amountMatchedByPledges);
    }

    public function testItSumsAmountsMatchedByAllFunds(): void
    {
        $donation = $this->getTestDonation();
        $campaignFunding0 = new CampaignFunding();
        $campaignFunding0->setFund(new ChampionFund());

        $withdrawal0 = new FundingWithdrawal($campaignFunding0);
        $withdrawal0->setAmount('1');

        $campaignFunding1 = new CampaignFunding();
        $campaignFunding1->setFund(new Pledge());
        $withdrawal1 = new FundingWithdrawal($campaignFunding1);
        $withdrawal1->setAmount('2');

        $donation->addFundingWithdrawal($withdrawal0);
        $donation->addFundingWithdrawal($withdrawal1);

        $amountMatchedByPledges = $donation->getFundingWithdrawalTotal();

        \assert(1 + 2 === 3);
        $this->assertSame('3.00', $amountMatchedByPledges);
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

        $donation = $this->getTestDonation(
            pspMethodType: PaymentMethodType::CustomerBalance,
            tipAmount: '0',
            currencyCode: 'SEK',
        );

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
        $this->assertSame('Test First Name Test Last Name', $donation->getDonorFullName());
    }

    /**
     * @return array<array{0: ?string, 1: ?string, 2: ?string}>
     */
    public function namePartsAndFullNames(): array
    {
        return [
            [null, null, null],
            ['Loraine ', null, 'Loraine'],
            [' Loraine ', ' James ', 'Loraine   James'],
            [null, 'James', 'James'],
        ];
    }

    /**
     * @return array<array{0: ?string, 1: string}>
     */
    public function namesAndSFSafeLastNames(): array
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
     * @return array<array{0: ?string, 1: ?string}>
     */
    public function namesAndSFSafeFirstNames(): array
    {
        return [
            // same as last name except we have null not 'N/A'.
            ['Flintstone', 'Flintstone'],
            [null, null],
            ['', null],
            [' ', null],
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
     * @dataProvider namePartsAndFullNames
     */
    public function testItMakesDonorFullName(?string $firstName, ?string $lastName, ?string $expectedFullName): void
    {
        $donation = $this->getTestDonation();
        $donation->setDonorFirstName($firstName);
        $donation->setDonorLastName($lastName);

        $this->assertSame($expectedFullName, $donation->getDonorFullName());
    }

    /**
     * @dataProvider namesAndSFSafeLastNames
     */
    public function testItMakesDonorLastNameSafeForSalesforce(?string $originalName, string $expecteSafeName): void
    {
        $donation = $this->getTestDonation();
        $donation->setDonorLastName($originalName);

        $this->assertSame($expecteSafeName, $donation->getDonorLastName(true));
    }

    /**
     * @dataProvider namesAndSFSafeFirstNames
     */
    public function testItMakesDonorFirstNameSafeForSalesforce(?string $originalName, ?string $expecteSafeName): void
    {
        $donation = $this->getTestDonation();
        $donation->setDonorFirstName($originalName);

        $this->assertSame($expecteSafeName, $donation->getDonorFirstName(true));
    }

    public function testCanCancelPendingDonation(): void
    {
        $donation = Donation::fromApiModel(
            new DonationCreate(
                'GBP',
                '1.00',
                'project-id',
                'stripe',
            ),
            $this->getMinimalCampaign()
        );
        $donation->cancel();

        $this->assertEquals(DonationStatus::Cancelled, $donation->getDonationStatus());
    }

    public function testCantCancelPaidDonation(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            'GBP',
            '1.00',
            'project-id',
            'stripe',
        ), $this->getMinimalCampaign());
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
        $donation = $this->getTestDonation();
        $this->expectExceptionMessage('Amount may not be changed after a donation is created');
        $changeset = [
            'amount' => ["1", "2"],
        ];
        $donation->preUpdate(new PreUpdateEventArgs(
            $donation,
            $this->createStub(EntityManagerInterface::class),
            $changeset,
        ));
    }

    /**
     * @dataProvider namesEnoughForSalesForce
     */
    public function testItHasEnoughDataForSalesforceOnlyIffBothNamesAreNonEmpty(
        string $firstName,
        string $lastName,
        bool $isEnoughForSalesforce,
    ): void {
        $donation = $this->getTestDonation();
        $donation->setDonorFirstName($firstName);
        $donation->setDonorLastName($lastName);
        $this->assertSame($isEnoughForSalesforce, $donation->hasEnoughDataForSalesforce());
    }

    /**
     * @return list<array{0: string, 1: string, 2: bool}>
     */
    public function namesEnoughForSalesForce(): array
    {
        return [
            // first name, last name, is it enough for SF?
            ['', '', false],
            ['', 'nonempty', false],
            ['nonempty', '', false],
            ['nonempty', 'nonempty', true],
        ];
    }
}
