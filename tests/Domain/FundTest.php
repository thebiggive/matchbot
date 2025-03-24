<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundType;
use MatchBot\Domain\Money;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;

class FundTest extends TestCase
{
    /**
     * @dataProvider amountsToSummarise
     */
    public function testGetAmounts(Money $totalAmount, Money $amountAvailable, Money $expectedUsedAmount): void
    {
        $fund = new Fund(
            'GBP',
            'testGetAmounts fund',
            Salesforce18Id::of('sfFundId4567890abc'),
            fundType: FundType::ChampionFund
        );
        $fund->addCampaignFunding(new CampaignFunding(
            fund: $fund,
            amount: $totalAmount->toNumericString(),
            amountAvailable: $amountAvailable->toNumericString(),
            allocationOrder: 100,
        ));

        $expected = [
            'totalAmount' => $totalAmount,
            'usedAmount' => $expectedUsedAmount,
        ];

        self::assertEquals($expected, $fund->getAmounts());
    }

    public function testToAmountUsedUpdateModel(): void
    {
        $fund = new Fund('GBP', 'Testfund', Salesforce18Id::of('sfFundId4567890abc'), fundType: FundType::ChampionFund);
        $fund->addCampaignFunding(new CampaignFunding(
            fund: $fund,
            amount: '123.45',
            amountAvailable: '100.00',
            allocationOrder: 100,
        ));

        $expected = [
            'currencyCode' => 'GBP',
            'fundId' => null, // Not actually persisting it.
            'fundType' => 'championFund',
            'salesforceFundId' => 'sfFundId4567890abc',
            'totalAmount' => 123.45,
            'usedAmount' => 23.45,
        ];

        self::assertSame($expected, $fund->toAmountUsedUpdateModel());
    }

    public function testChangingTypeUpdatesAllocationOrderOfAllCampaignFundings(): void
    {
        $fund = new Fund('GBP', 'Testfund', Salesforce18Id::of('sfFundId4567890abc'), fundType: FundType::Pledge);
        $campaignFunding = new CampaignFunding(
            fund: $fund,
            amount: '123.45',
            amountAvailable: '100.00',
            allocationOrder: FundType::Pledge->allocationOrder(),
        );
        $fund->addCampaignFunding($campaignFunding);

        $fund->changeTypeIfNecessary(FundType::TopupPledge);

        self::assertSame(FundType::TopupPledge->allocationOrder(), $campaignFunding->getAllocationOrder());
    }

    /**
     * Test data provider.
     *
     * @return list{array{
     *     amountAvailable: Money,
     *     expectedUsedAmount: Money,
     *     totalAmount: Money,
     * }}
     */
    private function amountsToSummarise(): array
    {
        /**
         * @var list{array{
         *  amountAvailable: Money,
         *  expectedUsedAmount: Money,
         *  totalAmount: Money,
         * }} $dataSets
         */
        $dataSets = [
            [
                'totalAmount' => Money::fromNumericStringGBP('123.45'),
                'amountAvailable' => Money::fromNumericStringGBP('100.00'),
                'expectedUsedAmount' => Money::fromNumericStringGBP('23.45'),
            ],
            [
                'totalAmount' => Money::fromNumericStringGBP('123.45'),
                'amountAvailable' => Money::fromNumericStringGBP('123.45'),
                'expectedUsedAmount' => Money::fromNumericStringGBP('0.00'),
            ],
            [
                'totalAmount' => Money::fromNumericStringGBP('0.01'),
                'amountAvailable' => Money::fromNumericStringGBP('0'),
                'expectedUsedAmount' => Money::fromNumericStringGBP('0.01'),
            ],
            [
                'totalAmount' => Money::fromNumericStringGBP('0'),
                'amountAvailable' => Money::fromNumericStringGBP('0'),
                'expectedUsedAmount' => Money::fromNumericStringGBP('0.0'),
            ],
        ];

        return $dataSets;
    }
}
