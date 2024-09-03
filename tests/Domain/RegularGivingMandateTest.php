<?php

namespace Domain;

use Brick\DateTime\LocalDate;
use Brick\DateTime\LocalDateTime;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\Currency;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationSequenceNumber;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\Money;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Tests\TestCase;
use Ramsey\Uuid\Uuid;
use UnexpectedValueException;

class RegularGivingMandateTest extends TestCase
{
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

        $donor = new DonorAccount(
            null,
            EmailAddress::of('fred@example.com'),
            DonorName::of('FirstName', 'LastName'),
            StripeCustomerId::of('cus_1234'),
        );

        $this->expectExceptionMessage('Cannot generate pre-authorized first donation');

        $mandate->createPreAuthorizedDonation(
            DonationSequenceNumber::of(1),
            $donor,
            $this->createStub(Campaign::class)
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

        $donor = new DonorAccount(
            null,
            EmailAddress::of('fred@example.com'),
            DonorName::of('FirstName', 'LastName'),
            StripeCustomerId::of('cus_1234'),
        );

        $donor->setHomePostcode('SW1A 1AA');
        $donor->setBillingPostcode('SW1A 1AA');
        $donor->setHomeAddressLine1('Address line 1');
        $donor->setBillingCountryCode('GB');

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

    public function testItRendersApiModel(): void
    {
        $charity = TestCase::someCharity(salesforceId: Salesforce18Id::ofCharity('charity89012345678'));

        $mandate = new RegularGivingMandate(
            donorId: PersonId::of('2c2b4832-563c-11ef-96a4-07141f9e507e'),
            amount: Money::fromPoundsGBP(500),
            dayOfMonth: DayOfMonth::of(12),
            campaignId: Salesforce18Id::ofCampaign('campaign9012345678'),
            charityId: Salesforce18Id::ofCharity(
                $charity->getSalesforceId() ?? throw new \Exception("sf id can't be null")
            ),
            giftAid: true,
        );

        $uuid = $mandate->uuid->toString();

        $now = new \DateTimeImmutable('2024-08-12', new \DateTimeZone('Europe/London'));

        $this->assertJsonStringEqualsJsonString(
            <<<JSON
                {
                  "id": "$uuid",
                  "donorId": "2c2b4832-563c-11ef-96a4-07141f9e507e",
                  "amount": {
                    "amountInPence": 50000,
                    "currency": "GBP"
                  },
                  "campaignId": "campaign9012345678",
                  "charityId": "charity89012345678",
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
            \json_encode($mandate->toFrontEndApiModel($charity, $now)),
        );
    }
    /** @dataProvider invalidRegularGivingAmounts */
    public function testItCannotBeTooSmallOrTooBig(int $pence, string $expectedMessage): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($expectedMessage);

        new RegularGivingMandate(
            donorId: PersonId::of('2c2b4832-563c-11ef-96a4-07141f9e507e'),
            amount: Money::fromPence($pence, Currency::GBP),
            dayOfMonth: DayOfMonth::of(12),
            campaignId: Salesforce18Id::ofCampaign('campaign9012345678'),
            charityId: Salesforce18Id::ofCharity('charity09012345678'),
            giftAid: true,
        );
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
}
