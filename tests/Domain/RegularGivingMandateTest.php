<?php

namespace Domain;

use MatchBot\Domain\Money;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;

class RegularGivingMandateTest extends TestCase
{
    public function testItRendersApiModel(): void
    {
        $mandate = new RegularGivingMandate(
            donorId: PersonId::of('2c2b4832-563c-11ef-96a4-07141f9e507e'),
            amount: Money::fromPoundsGBP(500),
            campaignId: Salesforce18Id::of('campaign9012345678'),
            charityId: Salesforce18Id::of('charity89012345678'),
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
                    "dayOfMonth": 31,
                    "activeFrom": "2024-08-06T00:00:00+00:00"
                  },
                  "charityName": "Some Charity",
                  "giftAid": true,
                  "status": "active",
                  "tipAmount": {
                    "amountInPence": 100,
                    "currency": "GBP"
                  }
                }
            JSON,
            \json_encode($mandate->toFrontEndApiModel())
        );
    }
}
