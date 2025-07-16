<?php

namespace MatchBot\Domain;

use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;

class MatchFundsRemainingServiceTest extends TestCase
{
    private MatchFundsService $SUT;

    /**
     * @var ObjectProphecy<CampaignFundingRepository>
     */
    private ObjectProphecy $campaignFundingRepositoryProphecy;

    /**
     * @var \WeakMap<Campaign, list<CampaignFunding>>
     */
    private \WeakMap $availableFundings;

    #[\Override]
    public function setUp(): void
    {
        /** @psalm-suppress PropertyTypeCoercion */
        $this->availableFundings = new \WeakMap();

        $this->campaignFundingRepositoryProphecy = $this->prophesize(CampaignFundingRepository::class);

        $testCase = $this;
        $this->campaignFundingRepositoryProphecy->getAvailableFundings(Argument::type(Campaign::class))->will(
            /** @param array{0: Campaign} $args */            function (array $args) use ($testCase) {
                                      return $testCase->availableFundings[$args[0]];
            }
        );

        $this->SUT = new MatchFundsService($this->campaignFundingRepositoryProphecy->reveal());
    }
    public function testItFindNothingAvailableForCampaignWithNoFunds(): void
    {
        $campaign = self::someCampaign();

        $this->availableFundings[$campaign] = [];

        $amountAvailable = $this->SUT->getFundsRemaining($campaign);

        $this->assertEquals(Money::zero(), $amountAvailable);
    }

    public function testItFindAmountAvailableForCampaignWithOneFund(): void
    {
        $campaign = self::someCampaign();

        $this->availableFundings[$campaign] = [
            new CampaignFunding(
                $this->anyFund(),
                '200.0',
                '123.45'
            )
        ];

        $amountAvailable = $this->SUT->getFundsRemaining($campaign);

        $this->assertEquals(
            Money::fromPence(123_45, Currency::GBP),
            $amountAvailable
        );
    }

    public function testItFindsAmountSumAvailableForCampaignWithTwoFund(): void
    {
        $campaign = self::someCampaign();

        $this->availableFundings[$campaign] = [
            new CampaignFunding(
                $this->anyFund(),
                amount: '200.0',
                amountAvailable: '123.45'
            ),
            new CampaignFunding(
                $this->anyFund(),
                amount: '300.0',
                amountAvailable: '23.45'
            )
        ];

        $amountAvailable = $this->SUT->getFundsRemaining($campaign);

        $this->assertEquals(
            Money::fromPence(146_90, Currency::GBP), // 123.45 + 12.45
            $amountAvailable
        );
    }

    public function testItCannotSumDifferentCurrencies(): void
    {
        $campaign = self::someCampaign();

        $this->availableFundings[$campaign] = [
            new CampaignFunding(
                $this->anyFund(currencyCode: 'GBP'),
                amount: '200.0',
                amountAvailable: '123.45'
            ),
            new CampaignFunding(
                $this->anyFund(currencyCode: 'USD'),
                amount: '300.0',
                amountAvailable: '23.45'
            )
        ];

        $this->expectExceptionMessage('fund currency code must equal campaign currency code');
        $this->SUT->getFundsRemaining($campaign);
    }

    /**
     * Details of fund don't matter
     */
    private function anyFund(string $currencyCode = 'GBP'): Fund
    {
        return new Fund(
            currencyCode: $currencyCode,
            name: 'fund name',
            slug: null,
            salesforceId: Salesforce18Id::ofFund('fundid123456789012'),
            fundType: FundType::Pledge
        );
    }
}
