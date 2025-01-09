<?php

namespace MatchBot\Tests\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\ChampionFund;
use MatchBot\Domain\Money;
use MatchBot\Tests\TestCase;

class FundTest extends TestCase
{
    private ChampionFund $fund;

    public function setUp(): void
    {
        $this->fund = new ChampionFund('GBP', 'Testfund');
        $this->fund->setSalesforceId('sfFundId456');

        $campaignFunding = new CampaignFunding(
            fund: $this->fund,
            amount: '123.45',
            amountAvailable: '100.00',
            allocationOrder: 100,
        );

        // Set campaignFundings with reflection to emulate ORM mapping.
        $campaignFundings = new \ReflectionProperty($this->fund, 'campaignFundings');
        $campaignFundings->setValue($this->fund, new ArrayCollection([$campaignFunding]));
    }

    public function testGetAmounts(): void
    {
        $expected = [
            'totalAmount' => Money::fromNumericStringGBP('123.45'),
            'usedAmount' => Money::fromNumericStringGBP('23.45'),
        ];

        self::assertEquals($expected, $this->fund->getAmounts());
    }

    public function testToAmountUsedUpdateModel(): void
    {
        $expected = [
            'currencyCode' => 'GBP',
            'fundId' => null, // Not actually persisting it.
            'fundType' => 'championFund',
            'salesforceFundId' => 'sfFundId456',
            'totalAmount' => '123.45',
            'usedAmount' => '23.45',
        ];

        self::assertEquals($expected, $this->fund->toAmountUsedUpdateModel());
    }
}
