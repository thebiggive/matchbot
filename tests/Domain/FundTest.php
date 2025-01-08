<?php

namespace MatchBot\Tests\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\ChampionFund;
use MatchBot\Tests\TestCase;

class FundTest extends TestCase
{
    public function testToAmountUsedUpdateModel(): void
    {
        $fund = new ChampionFund('GBP', 'Testfund');
        $fund->setSalesforceId('sfFundId456');

        $campaignFunding = new CampaignFunding(
            fund: $fund,
            amount: '123.45',
            amountAvailable: '100.00',
            allocationOrder: 100,
        );

        // Set campaignFundings with reflection to emulate ORM mapping.
        $campaignFundings = new \ReflectionProperty($fund, 'campaignFundings');
        $campaignFundings->setValue($fund, new ArrayCollection([$campaignFunding]));

        $expected = [
            'currencyCode' => 'GBP',
            'fundId' => null, // Not actually persisting it.
            'fundType' => 'championFund',
            'salesforceFundId' => 'sfFundId456',
            'totalAmount' => '123.45',
            'usedAmount' => '23.45',
        ];

        self::assertEquals($expected, $fund->toAmountUsedUpdateModel());
    }
}
