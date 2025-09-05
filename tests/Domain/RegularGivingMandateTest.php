<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Client\Fund;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\Country;
use MatchBot\Domain\Currency;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\DomainException\RegularGivingCollectionEndPassed;
use MatchBot\Domain\DonationSequenceNumber;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\Money;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\PostCode;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Tests\TestCase;
use Ramsey\Uuid\Uuid;
use UnexpectedValueException;

class RegularGivingMandateTest extends TestCase
{
    private const string PERSONID = '1acbcf6c-d81e-11ef-9c4c-37970561ab37';

    /** @dataProvider nextPaymentDates */
    public function testExpectedNextPaymentDate(
        string $currentDateTime,
        int $configuredPaymentDay,
        string $expected
    ): void {
        $mandate = new RegularGivingMandate(
            PersonId::of(Uuid::NIL),
            Money::fromPoundsGBP(1),
            Salesforce18Id::ofCampaign('a01234567890123AAB'),
            Salesforce18Id::ofCharity('a01234567890123AAB'),
            false,
            DayOfMonth::of($configuredPaymentDay),
        );

        $currentLondonTimeStamp = (new \DateTimeImmutable(
            $currentDateTime,
        ))->setTimezone(new \DateTimeZone('Europe/London'));

        $this->assertEquals(
            new \DateTimeImmutable($expected),
            $mandate->firstPaymentDayAfter($currentLondonTimeStamp)
        );
    }

    /**
     * The First donation in a regular giving mandate will always be taken on-session, not pre-authorized
     */
    public function testCannotGeneratePreAuthorizedFirstDonation(): void
    {
        $mandate = new RegularGivingMandate(
            PersonId::of(Uuid::NIL),
            Money::fromPoundsGBP(1),
            Salesforce18Id::ofCampaign('a01234567890123AAB'),
            Salesforce18Id::ofCharity('a01234567890123AAB'),
            false,
            DayOfMonth::of(1),
        );

        $mandate->activate((new \DateTimeImmutable(
            '2024-08-23T17:30:00Z',
        ))->setTimezone(new \DateTimeZone('Europe/London')));

        $donor = $this->someDonor();

        $this->expectExceptionMessage('Cannot generate pre-authorized first donation');

        $mandate->createPreAuthorizedDonation(
            DonationSequenceNumber::of(1),
            $donor,
            $this->createStub(Campaign::class)
        );
    }

    public function testCannotMakeDonationForAfterCollectionEnd(): void
    {
        //arrange
        $campaignId = Salesforce18Id::ofCampaign('a01234567890123AAB');
        $mandate = new RegularGivingMandate(
            PersonId::of(Uuid::NIL),
            Money::fromPoundsGBP(1),
            $campaignId,
            Salesforce18Id::ofCharity('a01234567890123AAB'),
            false,
            DayOfMonth::of(2),
        );
        $mandate->activate((new \DateTimeImmutable('2020-01-01')));

        $campaign = TestCase::someCampaign(
            sfId: $campaignId,
            isRegularGiving: true,
            regularGivingCollectionEnd: new \DateTimeImmutable('2020-01-01'),
        );

        // assert
        $this->expectException(RegularGivingCollectionEndPassed::class);
        $this->expectExceptionMessage(
            'Cannot pre-authorize a donation for 2020-01-02, regular giving collections for campaign a01234567890123AAB end at 2020-01-01'
        );

        // act
        $mandate->createPreAuthorizedDonation(
            DonationSequenceNumber::of(2),
            $this->someDonor(),
            $campaign
        );
    }

    /**
     * Given a mandate activated on a certain date, when would each donation relating to that mandate be payable?
     *
     * @dataProvider expectedDonationPreAuth
     */
    public function testPaymentDateForDonationNumber(
        string $activationTime,
        int $dayOfMonth,
        int $sequenceNo,
        string $expected
    ): void {
        $mandate = new RegularGivingMandate(
            PersonId::of(Uuid::NIL),
            Money::fromPoundsGBP(1),
            Salesforce18Id::ofCampaign('a01234567890123AAB'),
            Salesforce18Id::ofCharity('a01234567890123AAB'),
            false,
            DayOfMonth::of($dayOfMonth),
        );

        $mandate->activate((new \DateTimeImmutable(
            $activationTime,
        ))->setTimezone(new \DateTimeZone('Europe/London')));

        $donor = $this->someDonor();

        $donation = $mandate->createPreAuthorizedDonation(
            DonationSequenceNumber::of($sequenceNo),
            $donor,
            $this->createStub(Campaign::class)
        );
        $this->assertEquals(
            (new \DateTimeImmutable(
                $expected,
            ))->setTimezone(new \DateTimeZone('Europe/London')),
            $donation->getPreAuthorizationDate()
        );
    }

    public function testItRendersApiModelForFrontEnd(): void
    {
        $charity = TestCase::someCharity(salesforceId: Salesforce18Id::ofCharity('charity89012345678'));

        $mandate = new RegularGivingMandate(
            donorId: PersonId::of(self::PERSONID),
            donationAmount: Money::fromPoundsGBP(500),
            dayOfMonth: DayOfMonth::of(12),
            campaignId: Salesforce18Id::ofCampaign('campaign9012345678'),
            charityId: Salesforce18Id::ofCharity($charity->getSalesforceId()),
            giftAid: true,
        );

        $uuid = $mandate->getUuid();

        $now = new \DateTimeImmutable('2024-08-12', new \DateTimeZone('Europe/London'));

        $donorUUID = self::PERSONID;
        $this->assertJsonStringEqualsJsonString(
            <<<JSON
                {
                  "id": "$uuid",
                  "donorId": "$donorUUID",
                  "donationAmount": {
                    "amountInPence": 50000,
                    "currency": "GBP"
                  },
                  "isMatched" : true,
                  "matchedAmount": {
                    "amountInPence": 50000,
                    "currency": "GBP"
                  },
                  "giftAidAmount": {
                    "amountInPence": 12500,
                    "currency": "GBP"
                  },
                  "totalIncGiftAid": {
                    "amountInPence": 62500,
                    "currency": "GBP"
                  },
                  "totalCharityReceivesPerInitial": {
                    "amountInPence": 112500,
                    "currency": "GBP"
                  },
                  "campaignId": "campaign9012345678",
                  "charityId": "charity89012345678",
                  "numberOfMatchedDonations": 3,
                  "schedule": {
                    "type": "monthly",
                    "dayOfMonth": 12,
                    "activeFrom": null,
                    "expectedNextPaymentDate": "2024-09-12T06:00:00+01:00"
                  },
                  "charityName": "Charity Name",
                  "giftAid": true,
                  "status": "pending"
                }
            JSON,
            \json_encode($mandate->toFrontEndApiModel($charity, $now), \JSON_THROW_ON_ERROR),
        );
    }

    public function testItRendersApiModelForSalesforce(): void
    {
        $donor = self::someDonor();

        $mandate = new RegularGivingMandate(
            donorId: PersonId::of(self::PERSONID),
            donationAmount: Money::fromPoundsGBP(500),
            campaignId: Salesforce18Id::ofCampaign('campaign9012345678'),
            charityId: Salesforce18Id::ofCharity('charity90123456789'),
            giftAid: true,
            dayOfMonth: DayOfMonth::of(12),
        );
        $mandate->activate((new \DateTimeImmutable('2024-08-12T06:00:00Z')));

        $SFApiModel = $mandate->toSFApiModel($donor);


        $this->assertJsonStringEqualsJsonString(
            <<<JSON
            {
              "uuid":"{$mandate->getUuid()->toString()}",
              "contactUuid":"1acbcf6c-d81e-11ef-9c4c-37970561ab37",
              "donationAmount":500,
              "campaignSFId":"campaign9012345678",
              "giftAid":true,
              "dayOfMonth":12,
              "status":"Active",
              "activeFrom": "2024-08-12T06:00:00+00:00",
              "donor": {
                "firstName": "Fred",
                "lastName": "Do",
                "emailAddress":  "freddo@example.com",
                "billingPostalAddress": "SW1A 1AA",
                "countryCode": "GB",
                "pspCustomerId":  "cus_123456",
                "identityUUID": "1acbcf6c-d81e-11ef-9c4c-37970561ab37"
              }
            }
            JSON,
            \json_encode($SFApiModel, \JSON_THROW_ON_ERROR)
        );
    }

    /** @dataProvider invalidRegularGivingAmounts */
    public function testItCannotBeTooSmallOrTooBig(int $pence, string $expectedMessage): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($expectedMessage);

        new RegularGivingMandate(
            donorId: PersonId::of('1acbcf6c-d81e-11ef-9c4c-37970561ab37'),
            donationAmount: Money::fromPence($pence, Currency::GBP),
            dayOfMonth: DayOfMonth::of(12),
            campaignId: Salesforce18Id::ofCampaign('campaign9012345678'),
            charityId: Salesforce18Id::ofCharity('charity09012345678'),
            giftAid: true,
        );
    }

    public function testItReturnsAverageMatchedForOneUnMatchedDonation(): void
    {
        $donation = self::someDonation();

        $averageMatched = RegularGivingMandate::averageMatched([$donation]);

        $this->assertEquals(Money::zero(), $averageMatched);
    }

    public function testItReturnsAverageMatchedForOneMatchedDonation(): void
    {
        $donation = self::someDonation();
        $donation->addFundingWithdrawal($this->fakeWithdrawalOf('12.00'));

        $averageMatched = RegularGivingMandate::averageMatched([$donation]);

        $this->assertEquals(Money::fromPoundsGBP(12), $averageMatched);
    }

    public function testItReturnsAverageRoundedDownMatchedForOneMatchedDonation(): void
    {
        $donation = self::someDonation();
        $donation->addFundingWithdrawal($this->fakeWithdrawalOf('12.35'));

        $averageMatched = RegularGivingMandate::averageMatched([$donation]);

        $this->assertEquals(Money::fromPoundsGBP(12), $averageMatched);
    }

    public function testItReturnsAverageRoundedDownToZeroMatchedForThreeMatchedDonations(): void
    {
        $donations = [self::someDonation(), self::someDonation(), self::someDonation()];

        $donations[0]->addFundingWithdrawal($this->fakeWithdrawalOf('1.00'));
        $donations[1]->addFundingWithdrawal($this->fakeWithdrawalOf('1.00'));
        $donations[2]->addFundingWithdrawal($this->fakeWithdrawalOf('0.99'));

        // total is £2.99, average £0.996, rounds down to £0.00

        $averageMatched = RegularGivingMandate::averageMatched($donations);

        $this->assertEquals(Money::fromPoundsGBP(0), $averageMatched);
    }

    /** @return list<array{0: int, 1: string}> */
    public function invalidRegularGivingAmounts(): array
    {
        return [
            [99, 'Amount GBP 0.99 is out of allowed range GBP 1-GBP 500'],
            [500_01, 'Amount GBP 500.01 is out of allowed range GBP 1-GBP 500']
        ];
    }

    /** @return list<array{0: string, 1: int, 2: string}> */
    public function nextPaymentDates()
    {
        // Given a current DateTime, and a configured payment day, on what date do we expect the next payment to be
        // made?

        // We will assume that we have already taken any payment that we are entitled to take, so the earliest possible
        // expected next payment day is tomorrow.

        // cases:
        // Payment day is today. (not really a case as we don't count today as next)
        // Payment day is later this month. Means today's day number is < to configured number.
        // Payment day is next month. Today's day number is >= configured number.

        return [
            // current date, configured payment day, expected next payment day
            ['2024-08-23T17:30:00Z', 23, '2024-09-23T06:00:00+0100'],
            ['2024-12-23T17:30:00Z', 23, '2025-01-23T06:00:00+0000'], // TZ is +0 because its winter
            ['2024-08-22T17:30:00Z', 23, '2024-08-23T06:00:00+0100'],
            ['2024-08-22T23:30:00Z', 23, '2024-09-23T06:00:00+0100'], // Near midnight UTC; sign up is 23 Aug UK time
        ];
    }

    /** @return list<array{0: string, 1: int, 2: int, 3: string}> */
    public function expectedDonationPreAuth(): array
    {
        return [
            // activationTime, dayOfMonth, sequenceNo, expected
            // E.g. If mandate is activated on August 23rd, payable on the 1st of each month, then the 2nd donation is
            // payable on September 1st.
            ['2024-08-23T17:30:00Z', 1, 2, '2024-09-01T06:00:00+0100'],
            ['2024-08-23T17:30:00Z', 1, 3, '2024-10-01T06:00:00+0100'],
            ['2024-08-23T17:30:00Z', 1, 4, '2024-11-01T06:00:00+0100'],

            ['2024-08-23T17:30:00Z', 23, 2, '2024-09-23T06:00:00+0100'],
            ['2024-08-23T17:30:00Z', 23, 3, '2024-10-23T06:00:00+0100'],
        ];
    }

    public static function someDonor(): DonorAccount
    {
        $donor = new DonorAccount(
            PersonId::of(self::PERSONID),
            EmailAddress::of('freddo@example.com'),
            DonorName::of('Fred', 'Do'),
            StripeCustomerId::of('cus_123456'),
        );
        $donor->setHomePostcode(PostCode::of('SW1A 1AA'));
        $donor->setBillingPostcode('SW1A 1AA');
        $donor->setHomeAddressLine1('Address line 1');
        $donor->setBillingCountry(Country::GB());

        return $donor;
    }

    private function fakeWithdrawalOf(string $amount): FundingWithdrawal
    {
        // Faking the FundingWithdrawal because it's awkward to instantiate
        // - would need a CampaignFunding which would need Fund, each with constructor params.
        $fundingWithdrawalProphecy = $this->prophesize(FundingWithdrawal::class);
        $fundingWithdrawalProphecy->getAmount()->willReturn($amount);

        return $fundingWithdrawalProphecy->reveal();
    }
}
