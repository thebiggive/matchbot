<?php

declare(strict_types=1);

namespace MatchBot\Tests\Domain;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use MatchBot\Application\AssertionFailedException;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\LazyAssertionException;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CardBrand;
use MatchBot\Domain\ChampionFund;
use MatchBot\Domain\Country;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\Money;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\Pledge;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Ramsey\Uuid\Uuid;

class DonationTest extends TestCase
{
    use DonationTestDataTrait;

    public const string DONATION_UUID = 'c6479a48-b405-11ef-a911-8b225323866a';

    public function testBasicsAsExpectedOnInstantion(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '1',
            projectId: 'projectid012345678',
            psp:'stripe',
            pspMethodType: PaymentMethodType::Card
        ), $this->getMinimalCampaign());

        $this->assertFalse($donation->getDonationStatus()->isSuccessful());
        $this->assertEquals('pending-create', $donation->getSalesforcePushStatus());
        $this->assertNull($donation->getSalesforceId());
        $this->assertFalse($donation->hasGiftAid());
        $this->assertNull($donation->getCharityComms());
        $this->assertNull($donation->getTbgComms());
    }

    /**
     * @dataProvider giftAidProvider
     * @param numeric-string $donationAmount
     * @param numeric-string $expectedGAAmount
     */
    public function testCalculatesGiftAid(string $donationAmount, bool $hasGiftAid, string $expectedGAAmount): void
    {
        $donation = TestCase::someDonation(amount: $donationAmount, giftAid: $hasGiftAid);

        $this->assertSame($expectedGAAmount, $donation->getGiftAidValue());
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
        $this->expectExceptionMessage('Amount 0.99 is out of allowed range 1-25000 GBP');

        $this->getTestDonation('0.99');
    }

    public function testAmountVerySlightlyTooLowNotPersisted(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Amount 0.99999999999999999 is out of allowed range 1-25000 GBP');

        // PHP floating point math doesn't distinguish between this and 1, but as we use BC Math we can reject it as
        // too small:
        // See https://3v4l.org/#live
        $justLessThanOne = '0.99999999999999999';
        $this->getTestDonation($justLessThanOne);
    }

    public function testAmountTooHighNotPersisted(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Amount 25000.01 is out of allowed range 1-25000 GBP');

        $this->getTestDonation('25000.01');
    }

    public function test25k1CardIsTooHigh(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Amount 25001 is out of allowed range 1-25000 GBP');

        Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '25001',
            projectId: 'projectid012345678',
            psp:'stripe',
            pspMethodType: PaymentMethodType::Card
        ), $this->getMinimalCampaign());
    }

    public function test200kCustomerBalanceDonationIsAllowed(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '200000',
            projectId: 'projectid012345678',
            psp:'stripe',
            pspMethodType: PaymentMethodType::CustomerBalance
        ), $this->getMinimalCampaign());

        $this->assertSame('200000', $donation->getAmount());
    }

    public function test200k1CustomerBalanceDonationIsTooHigh(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Amount 200001 is out of allowed range 1-200000 GBP');

        Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '200001',
            projectId: 'projectid012345678',
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
            projectId: 'projectid012345678',
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
                projectId: 'doesnt0matter12345',
                psp: 'paypal',
            ),
            TestCase::someCampaign()
        );
    }

    /**
     * @doesNotPerformAssertions Just check setPsp() doesn't hit an exception
     */
    public function testValidPspAccepted(): void
    {
        $_donation = $this->getTestDonation();
    }

    public function testSetAndGetOriginalFee(): void
    {
        $donation = $this->getTestDonation();
        $donation->setOriginalPspFeeFractional('123');

        $this->assertEquals('1.23', $donation->getOriginalPspFee());
    }

    public function testtoFrontEndApiModel(): void
    {
        $pledge = new Fund(currencyCode: 'GBP', name: '', salesforceId: null);

        $campaignFunding = new CampaignFunding(
            fund: $pledge,
            amount: '1000',
            amountAvailable: '1.23',
            allocationOrder: 100,
        );

        $fundingWithdrawal = new FundingWithdrawal($campaignFunding);
        $fundingWithdrawal->setAmount('1.23');
        $donation = $this->getTestDonation();
        $donation->addFundingWithdrawal($fundingWithdrawal);

        $donationData = $donation->toFrontEndApiModel();

        $this->assertEquals('john.doe@example.com', $donationData['emailAddress']);
        $this->assertEquals('1.23', $donationData['matchedAmount']);
        $this->assertIsString($donationData['collectedTime']);
        $this->assertArrayNotHasKey('originalPspFee', $donationData);

        $donationDataIncludingPrivate = $donation->toSFApiModel();
        $this->assertEquals('1.22', $donationDataIncludingPrivate['originalPspFee']);
    }

    public function testToSfAPIModel(): void
    {
        $donation = self::someDonation(amount: '10', tipAmount: '1');

        $this->assertEquals(
            [
                'amountMatchedByChampionFunds' => 0.0,
                'amountMatchedByPledges' => 0.0,
                'billingPostalAddress' => null,
                'charityFee' => 0.0,
                'charityFeeVat' => 0.0,
                'charityId' => $donation->getCampaign()->getCharity()->getSalesforceId(),
                'charityName' => 'Charity Name',
                'collectedTime' => null,
                'countryCode' => null,
                'createdTime' => $donation->getCreatedDate()->format('c'),
                'currencyCode' => 'GBP',
                'donationAmount' => 10.0,
                'donationId' => $donation->getUuid(),
                'donationMatched' => false,
                'emailAddress' => null,
                'firstName' => null,
                'giftAid' => false,
                'homeAddress' => null,
                'homePostcode' => null,
                'lastName' => 'N/A',
                'mandate' => null,
                'matchedAmount' => 0,
                'optInChampionEmail' => null,
                'optInCharityEmail' => null,
                'optInTbgEmail' => null,
                'originalPspFee' => 0.0,
                'projectId' => $donation->getCampaign()->getSalesforceId(),
                'psp' => 'stripe',
                'pspCustomerId' => null,
                'pspMethodType' => 'card',
                'refundedTime' => null,
                'status' => DonationStatus::Pending,
                'tbgGiftAidRequestConfirmedCompleteAt' => null,
                'tipAmount' => 1.0,
                'tipGiftAid' => null,
                'tipRefundAmount' => null,
                'totalPaid' => null,
                'transactionId' => null,
                'updatedTime' => $donation->getUpdatedDate()->format('c'),
            ],
            $donation->toSfAPIModel()
        );
    }

    public function testAmountMatchedByChampionDefaultsToZero(): void
    {
        $donation = $this->getTestDonation();

        $amountMatchedByChampionFunds = $donation->toFrontEndApiModel()['amountMatchedByChampionFunds'];

        $this->assertSame(0.0, $amountMatchedByChampionFunds);
    }

    public function testItSumsNoChampionFundsToZero(): void
    {
        $donation = $this->getTestDonation();

        $amountMatchedByPledges = $donation->toFrontEndApiModel()['amountMatchedByChampionFunds'];

        $this->assertSame(0.0, $amountMatchedByPledges);
    }

    public function testItSumsAmountsMatchedByChampionFunds(): void
    {
        $donation = $this->getTestDonation();

        $campaignFunding = new CampaignFunding(
            fund: new ChampionFund(currencyCode: 'GBP', name: '', salesforceId: null),
            amount: '1000',
            amountAvailable: '1000',
            allocationOrder: 100,
        );
        $withdrawal0 = new FundingWithdrawal($campaignFunding);
        $withdrawal0->setAmount('1');

        $withdrawal1 = clone $withdrawal0;
        $withdrawal1->setAmount('2');


        $donation->addFundingWithdrawal($withdrawal0);
        $donation->addFundingWithdrawal($withdrawal1);

        $amountMatchedByPledges = $donation->toFrontEndApiModel()['amountMatchedByChampionFunds'];

        \assert(1 + 2 === 3);
        $this->assertSame(3.0, $amountMatchedByPledges);
    }

    public function testItSumsAmountsMatchedByAllFunds(): void
    {
        $donation = $this->getTestDonation();
        $campaignFunding0 = new CampaignFunding(
            fund: new Fund(currencyCode: 'GBP', name: '', salesforceId: null, type: 'championFund'),
            amount: '1000',
            amountAvailable: '1000',
            allocationOrder: 100,
        );

        $withdrawal0 = new FundingWithdrawal($campaignFunding0);
        $withdrawal0->setAmount('1');

        $campaignFunding1 = new CampaignFunding(
            fund: new Fund(currencyCode: 'GBP', name: '', salesforceId: null, type: 'championFund'),
            amount: '1000',
            amountAvailable: '1000',
            allocationOrder: 100,
        );
        $withdrawal1 = new FundingWithdrawal($campaignFunding1);
        $withdrawal1->setAmount('2');

        $donation->addFundingWithdrawal($withdrawal0);
        $donation->addFundingWithdrawal($withdrawal1);

        $amountMatchedByPledges = $donation->getFundingWithdrawalTotal();

        \assert(1 + 2 === 3);
        $this->assertSame('3.00', $amountMatchedByPledges);
    }

    public function testtoFrontEndApiModelWhenRefunded(): void
    {
        $donation = $this->getTestDonation();
        $donation->recordRefundAt(new \DateTimeImmutable());

        $donationData = $donation->toFrontEndApiModel();

        $this->assertIsString($donationData['collectedTime']);
        $this->assertIsString($donationData['refundedTime']);
    }

    public function testToClaimBotModelUK(): void
    {
        $donation = $this->getTestDonation(uuid: Uuid::fromString(self::DONATION_UUID));

        $donation->getCampaign()->getCharity()->setTbgClaimingGiftAid(true);
        $donation->getCampaign()->getCharity()->setHmrcReferenceNumber('AB12345');
        $donation->getCampaign()->getCharity()->setRegulator('CCEW');
        $donation->getCampaign()->getCharity()->setRegulatorNumber('12229');
        $donation->setTbgShouldProcessGiftAid(true);

        $claimBotMessage = $donation->toClaimBotModel();

        $nowInYmd = date('Y-m-d');
        $this->assertEquals(self::DONATION_UUID, $claimBotMessage->id);
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

        $donationApiModel = $donation->toFrontEndApiModel();

        $this->assertSame(DonationStatus::Refunded, $donationApiModel['status']);
        $this->assertSame('2023-06-22T15:00:00+00:00', $donationApiModel['refundedTime']);
    }

    public function testIsReadyToConfirmWithRequiredFieldsSet(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBB',
            donationAmount: '1',
            projectId: '123456789012345678',
            psp: 'stripe',
            firstName: null,
            lastName: null,
            emailAddress: 'user@example.com',
            countryCode: 'GB',
        ), TestCase::someCampaign());

        $donation->update(
            giftAid: false,
            donorBillingPostcode: 'SW1 1AA',
            donorName: DonorName::of('Charlie', 'The Charitable'),
            donorEmailAddress: EmailAddress::of('user@example.com'),
            tbgComms: false,
        );

        $donation->setTransactionId('any-string');

        $this->assertTrue($donation->assertIsReadyToConfirm());
    }

    public function testIsNotReadyToConfirmWithoutBillingPostcode(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBB',
            donationAmount: '1',
            projectId: '123456789012345678',
            psp: 'stripe',
            firstName: 'Chelsea',
            lastName: 'Charitable',
            emailAddress: 'user@example.com',
            countryCode: 'GB',
        ), TestCase::someCampaign());

        $donation->update(
            giftAid: false,
            donorBillingPostcode: null,
            donorName: DonorName::of('Charlie', 'The Charitable'),
            donorEmailAddress: EmailAddress::of('user@example.com'),
        );

        $this->expectException(LazyAssertionException::class);
        $this->expectExceptionMessage("Missing Billing Postcode");

        $donation->assertIsReadyToConfirm();
    }

    public function testIsNotReadyToConfirmWithoutTBGComsPreference(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBB',
            donationAmount: '1',
            projectId: '123456789012345678',
            psp: 'stripe',
            firstName: 'Chelsea',
            lastName: 'Charitable',
            emailAddress: 'user@example.com',
            countryCode: 'GB',
        ), TestCase::someCampaign());

        $donation->update(
            giftAid: false,
            donorBillingPostcode: 'SW1A 1AA',
            donorName: DonorName::of('Charlie', 'The Charitable'),
            donorEmailAddress: EmailAddress::of('user@example.com'),
            tbgComms: null,
        );

        $this->expectException(LazyAssertionException::class);
        $this->expectExceptionMessage("Missing tbgComms preference");

        $donation->assertIsReadyToConfirm();
    }

    public function testIsNotReadyToConfirmWithoutCharityComsPreference(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBB',
            donationAmount: '1',
            projectId: '123456789012345678',
            psp: 'stripe',
            firstName: 'Chelsea',
            lastName: 'Charitable',
            emailAddress: 'user@example.com',
            countryCode: 'GB',
        ), TestCase::someCampaign());

        $donation->update(
            giftAid: false,
            donorBillingPostcode: 'SW1A 1AA',
            donorName: DonorName::of('Charlie', 'The Charitable'),
            donorEmailAddress: EmailAddress::of('user@example.com'),
            tbgComms: false,
            charityComms: null,
        );

        $this->expectException(LazyAssertionException::class);
        $this->expectExceptionMessage("Missing charityComms preference");

        $donation->assertIsReadyToConfirm();
    }

    public function testIsNotReadyToConfirmWithoutBillingCountry(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBB',
            donationAmount: '1',
            projectId: '123456789012345678',
            psp: 'stripe',
            firstName: 'Chelsea',
            lastName: 'Charitable',
            emailAddress: 'user@example.com',
        ), TestCase::someCampaign());

        $this->expectException(LazyAssertionException::class);
        $this->expectExceptionMessage("Missing Billing Postcode");

        $donation->assertIsReadyToConfirm();
    }

    public function testIsNotReadyToConfirmWithoutDonorName(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBB',
            donationAmount: '1',
            projectId: '123456789012345678',
            psp: 'stripe',
            firstName: null,
            lastName: null,
        ), TestCase::someCampaign());

        $this->expectException(LazyAssertionException::class);
        $this->expectExceptionMessage("Missing Donor First Name");
        $this->expectExceptionMessage("Missing Donor Last Name");

        $donation->assertIsReadyToConfirm();
    }

    public function testIsNotReadyToConfirmWithoutDonorEmail(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBB',
            donationAmount: '1',
            projectId: '123456789012345678',
            psp: 'stripe',
            firstName: 'First',
            lastName: 'Last',
        ), TestCase::someCampaign());

        $this->expectException(LazyAssertionException::class);
        $this->expectExceptionMessage("Missing Donor Email Address");

        $donation->assertIsReadyToConfirm();
    }

    public function testIsNotReadyToConfirmWhenCancelled(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBB',
            donationAmount: '1',
            projectId: '123456789012345678',
            psp: 'stripe',
            firstName: 'First',
            lastName: 'Last',
        ), TestCase::someCampaign());

        $donation->cancel();

        $this->expectException(LazyAssertionException::class);
        $this->expectExceptionMessage("Donation status is 'Cancelled', must be 'Pending'");

        $donation->assertIsReadyToConfirm();
    }



    public function testMarkingRefundTwiceOnSameDonationDoesNotUpdateRefundTime(): void
    {
        $donation = $this->getTestDonation();

        $donation->recordRefundAt(new \DateTimeImmutable('2023-06-22 15:00'));
        $donation->recordRefundAt(new \DateTimeImmutable('2023-06-22 16:00'));

        $donationApiModel = $donation->toFrontEndApiModel();

        $this->assertSame(DonationStatus::Refunded, $donationApiModel['status']);
        $this->assertSame('2023-06-22T15:00:00+00:00', $donationApiModel['refundedTime']);
    }

    public function testCreateDonationModelWithDonorFields(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            firstName: 'Test First Name',
            lastName: 'Test Last Name',
            emailAddress: 'donor@email.test',
            currencyCode: 'GBP',
            donationAmount: '200000',
            projectId: 'projectid012345678',
            psp:'stripe',
            pspMethodType: PaymentMethodType::CustomerBalance
        ), $this->getMinimalCampaign());

        $this->assertSame('Test First Name', $donation->getDonorFirstName(true));
        $this->assertSame('Test Last Name', $donation->getDonorLastName(true));
        $this->assertEquals(EmailAddress::of('donor@email.test'), $donation->getDonorEmailAddress());
        $this->assertSame('Test First Name Test Last Name', $donation->getDonorFullName());
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
            [str_repeat('👩‍👩‍👧‍👧', 36), '👩‍👩‍👧‍👧👩‍👩‍👧‍👧👩‍👩‍👧‍👧👩‍👩‍👧‍👧👩‍👩‍👧‍👧👩‍👩‍👧'],
        ];
    }

    public function testItMakesDonorFullName(): void
    {
        $donation = $this->getTestDonation();
        $donation->setDonorName(DonorName::of(' Loraine ', ' James '));

        $this->assertSame('Loraine   James', $donation->getDonorFullName());
    }

    /**
     * @dataProvider namesAndSFSafeLastNames
     */
    public function testItMakesDonorLastNameSafeForSalesforce(?string $originalName, string $expecteSafeName): void
    {
        $donation = $this->getTestDonation();
        $donation->setDonorName(DonorName::maybeFromFirstAndLast($originalName, $originalName));

        $this->assertSame($expecteSafeName, $donation->getDonorLastName(true));
    }

    /**
     * @dataProvider namesAndSFSafeFirstNames
     */
    public function testItMakesDonorFirstNameSafeForSalesforce(?string $originalName, ?string $expecteSafeName): void
    {
        $donation = $this->getTestDonation();

        $donation->setDonorName(DonorName::maybeFromFirstAndLast($originalName, $originalName));

        $this->assertSame($expecteSafeName, $donation->getDonorFirstName(true));
    }

    public function testCanCancelPendingDonation(): void
    {
        $donation = Donation::fromApiModel(
            new DonationCreate(
                'GBP',
                '1.00',
                'projectid012345678',
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
            'projectid012345678',
            'stripe',
        ), $this->getMinimalCampaign());
        $donation->setDonationStatus(DonationStatus::Paid);

        $this->expectExceptionMessage('Cannot cancel Paid donation');
        $donation->cancel();
    }

    public function testCannotCreateDonationWithNegativeTip(): void
    {
        $this->expectException(AssertionFailedException::class);

        Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '10',
            projectId: 'projectid012345678',
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
            projectId: 'testProject1234567',
            psp: 'stripe',
        ), TestCase::someCampaign());

        $this->assertSame($expected, $donation->getDonorCountryCode());
    }

    /**
     * HMRC will not process a gift aid claim without the donors home address, so we should
     * also not accept an instruction to claim to claim gift aid if we don't have the donor's address.
     */
    public function testCannotRequestGiftAidWithoutHomeAddress(): void
    {
        $donation = Donation::fromApiModel(new DonationCreate(
            countryCode: 'GB',
            currencyCode: 'GBP',
            donationAmount: '1.0',
            projectId: 'testProject1234567',
            psp: 'stripe',
        ), TestCase::someCampaign());

        $this->expectExceptionMessage('Cannot Claim Gift Aid Without Home Address');

        $donation->update(
            giftAid: true,
            donorHomeAddressLine1: null,
        );
    }

    public function testCannotRequestGiftAidWithWhitespaceOnlyHomeAddress(): void
    {
        $donation = Donation::fromApiModel(
            new DonationCreate(
                countryCode: 'GB',
                currencyCode: 'GBP',
                donationAmount: '1.0',
                projectId: 'testProject1234567',
                psp: 'stripe',
            ),
            TestCase::someCampaign()
        );

        $this->expectExceptionMessage('Cannot Claim Gift Aid Without Home Address');

        $donation->update(
            giftAid: true,
            donorHomeAddressLine1: '   ',
        );
    }

    public function testCannotUpdateADonationAfterCollection(): void
    {
        // arrange
        $donation = Donation::fromApiModel(new DonationCreate(
            countryCode: 'GB',
            currencyCode: 'GBP',
            donationAmount: '1.0',
            projectId: 'testProject1234567',
            psp: 'stripe',
        ), TestCase::someCampaign());

        $donation->collectFromStripeCharge(
            chargeId: 'irrelevant',
            totalPaidFractional: 100, // irrelevant
            transferId: 'irrelevant',
            cardBrand: CardBrand::visa,
            cardCountry: Country::fromAlpha2('gb'),
            originalFeeFractional: '1',
            chargeCreationTimestamp: 1,
        );

        // assert
        $this->expectExceptionMessage('Update only allowed for pending donation');

        // act
        $donation->update(
            giftAid: true,
            donorHomeAddressLine1: 'Updated home address',
        );
    }

    public function testCannotSetTooLongHomeAddress(): void
    {
        $donation = $this->getTestDonation(collected: false);

        $this->expectExceptionMessage('too long, it should have no more than 255 characters, but has 256 characters');
        $donation->update(
            giftAid: false,
            donorHomeAddressLine1: str_repeat('a', 256),
        );
    }

    public function testCannotSetTooLongPostcode(): void
    {
        $donation = $this->getTestDonation(collected: false);

        $this->expectExceptionMessage('too long, it should have no more than 8 characters, but has 43 characters');
        $donation->update(
            giftAid: true,
            donorHomeAddressLine1: 'a pretty how town',
            donorHomePostcode: 'This is too long to be a plausible postcode'
        );
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
            ['be', 'BE']
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
        $donation->setDonorName(DonorName::maybeFromFirstAndLast($firstName, $lastName));
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
            ['nonempty', 'nonempty', true],
        ];
    }

    public function testTotalPaidByDonorIsNullForIncompleteDonation(): void
    {
        $donation = $this->getTestDonation(collected: false, amount: '6.00', tipAmount: '5.00');
        $this->assertNull($donation->getTotalPaidByDonor());
    }


    public function testTotalPaidByDonorForCollectedDonation(): void
    {
        $donation = $this->getTestDonation(collected: true, amount: '6.00', tipAmount: '5.00');
        $this->assertSame('11.00', $donation->getTotalPaidByDonor());
    }

    public function testThrowsIfTotalPaidByDonorForCollectedDonationIsUnexpectedAmount(): void
    {
        $donation = $this->getTestDonation(collected: false, amount: '6.00', tipAmount: '5.00');
        $donation->collectFromStripeCharge(
            chargeId: 'chargid',
            totalPaidFractional: 100, // we expected to receive 1100 pence, not just 100.
            transferId: 'transferId',
            cardBrand: null,
            cardCountry: null,
            originalFeeFractional: '0',
            chargeCreationTimestamp: time()
        );

        $this->expectExceptionMessage('Value "1.00" does not equal expected value "11.00".');
        $donation->getTotalPaidByDonor();
    }

    public function testTotalPaidByDonorForCollectedRefundedDonation(): void
    {
        $donation = $this->getTestDonation(collected: true, amount: '6.00', tipAmount: '5.00');
        $donation->recordRefundAt(new \DateTimeImmutable());
        $this->assertSame('11.00', $donation->getTotalPaidByDonor());
    }

    public function testDonationWithNoFundingIsNotFullyMatched(): void
    {
        $donation = $this->getTestDonation();

        $this->assertFalse($donation->isFullyMatched());
    }

    public function testAlmostMatchedDonationIsNotFullyMatched(): void
    {
        $donation = $this->getTestDonation(amount: '100.00');

        $fund = new ChampionFund('GBP', 'some champion fund', null);
        $campaignFunding = new CampaignFunding($fund, amount: '1000', amountAvailable: '1000', allocationOrder: 1);
        $fundingWithdrawl = new FundingWithdrawal($campaignFunding);
        $fundingWithdrawl->setAmount('99.99');
        $donation->addFundingWithdrawal($fundingWithdrawl);

        $this->assertFalse($donation->isFullyMatched());
    }

    public function testFullyMatchedDonationIsFullyMatched(): void
    {
        $donation = $this->getTestDonation(amount: '100.00');

        $fund = new ChampionFund('GBP', 'some champion fund', null);
        $campaignFunding = new CampaignFunding($fund, '1000', '1000', 1);
        $fundingWithdrawl = new FundingWithdrawal($campaignFunding);
        $fundingWithdrawl->setAmount('100.00');
        $donation->addFundingWithdrawal($fundingWithdrawl);

        $isFullyMatched = $donation->isFullyMatched();

        $this->assertTrue($isFullyMatched);
    }


    /**
     * @return array<string, array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: bool}>
     */
    public function confirmationDateRangeProvider(): array
    {
        return [
            'current time before preauth time' => [
                new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2019-12-31T23:59'), false
            ],
            'current time same as preauth time' => [
                new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2020-01-01'), true
            ],
            'current time a month after preauth time' => [
                new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2020-02-01'), true
            ],
            'current time more than a month after preauth time' => [
                new \DateTimeImmutable('2020-01-01'), new \DateTimeImmutable('2020-02-01T00:01'), false
            ],
        ];
    }

    /**
     * @dataProvider confirmationDateRangeProvider
     */
    public function testAllowConfirmingOnlyDuringAppropriateDateRange(
        \DateTimeImmutable $preAuthDate,
        \DateTimeImmutable $at,
        bool $expected
    ): void {
        $donation = $this->getTestDonation(collected: false);
        $donation->preAuthorize($preAuthDate);

        $this->assertSame($expected, $donation->thisIsInDateRangeToConfirm($at));
    }

    /**
     * @return list<array{0: numeric-string, 1: bool, 2: numeric-string}>
     */
    public function giftAidProvider(): array
    {
        // including cases with fractional donation amounts, but as we do not allow such donations the actual
        // rounding method used is probably not critcal.
        return [
            ['1.00', true, '0.25'],
            ['1.03', true, '0.25'],
            ['1.04', true, '0.26'],

            ['1.00', false, '0.00'],
        ];
    }

    public function testSetsTipRefunded(): void
    {
        $now = new \DateTimeImmutable('2025-01-01T00:00:00Z');
        $donation = self::someDonation(tipAmount: '23.53');
        $refundAmount = Money::fromNumericStringGBP('23.53');
        $donation->setTipRefunded($now, $refundAmount);

        $this->assertSame($now, $donation->getRefundedAt());
        $this->assertEquals($refundAmount, $donation->getTipRefundAmount());
    }
}
