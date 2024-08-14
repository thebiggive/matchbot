<?php

namespace Domain;

use MatchBot\Domain\Currency;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\Money;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;
use UnexpectedValueException;

class RegularGivingMandateTest extends TestCase
{
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
                    "activeFrom": null
                  },
                  "charityName": "Charity Name",
                  "giftAid": true,
                  "status": "pending"
                }
            JSON,
            \json_encode($mandate->toFrontEndApiModel($charity))
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
            [99, 'Amount 0.99GBP is out of allowed range 1GBP-500GBP'],
            [500_01, 'Amount 500.01GBP is out of allowed range 1GBP-500GBP']
        ];
    }
}
