<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignService;
use MatchBot\Domain\Currency;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\MatchFundsService;
use MatchBot\Domain\MetaCampaignRepository;
use MatchBot\Domain\Money;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Clock\MockClock;

class CampaignServiceTest extends TestCase
{
    private CampaignService $SUT;

    /** @var ObjectProphecy<MetaCampaignRepository> */
    private $metaCampaignRepositoryProphecy;

    #[\Override]
    public function setUp(): void
    {
        parent::setUp();

        // having all these stubs here suggests probably this service class should be broken up so the part that
        // doesn't use the dependances can be tested separately.
        $this->metaCampaignRepositoryProphecy = $this->prophesize(MetaCampaignRepository::class);
        $matchFundsServiceProphecy = $this->prophesize(MatchFundsService::class);
        $matchFundsServiceProphecy->getFundsRemainingForMetaCampaign(Argument::any())->willReturn(Money::zero());

        $this->SUT = new CampaignService(
            campaignRepository: $this->createStub(CampaignRepository::class),
            metaCampaignRepository: $this->metaCampaignRepositoryProphecy->reveal(),
            cache: new NullAdapter(),
            donationRepository: $this->createStub(DonationRepository::class),
            matchFundsService: $matchFundsServiceProphecy->reveal(),
            log: $this->createStub(LoggerInterface::class),
            clock: new MockClock(new \DateTimeImmutable('1970-01-01')),
        );
    }

    public function testItRendersCampaignWithDetailsOfRelatedMetaCampaignWithSharedFunds(): void
    {
        // arrange
        $metaCampaign = self::someMetaCampaign(isRegularGiving: true, isEmergencyIMF: false);
        $metaCampaign->setId(43);
        $campaign = self::someCampaign(metaCampaignSlug: $metaCampaign->getSlug());


        $this->metaCampaignRepositoryProphecy->countCompleteDonationsToMetaCampaign($metaCampaign)->willReturn(3);
        $this->metaCampaignRepositoryProphecy->totalAmountRaised($metaCampaign)->willReturn(Money::fromPoundsGBP(12));

        // act
        $renderedCampaign = $this->SUT->renderCampaign($campaign, $metaCampaign);

        // assert
        // Because the parent i.e. metacampaign is regular giving funds will be shared with any other charity campaigns.
        $this->assertTrue($renderedCampaign['parentUsesSharedFunds']);
    }

    public function testItDeDupesBudgetDetails(): void
    {
        $campaign = self::someCampaign();

        $renderedCamapign = $this->SUT->renderCampaign($campaign, null);

        // source data in TestCase::CAMPAIGN_FROM_SALESFORCE
        // has the first detail duplicated to simulate data accidentally duplicated
        //  in the SF API which we know can happen. Not duplicated in the output below.

        $this->assertSame([
            ['amount' => 23, 'description' => 'Improve the code'],
            ['amount' => 27, 'description' => 'Invent a new programing paradigm'],
        ], $renderedCamapign['budgetDetails']);
    }


    public function testItRendersCampaignWithDetailsOfRelatedMetaCampaignWithNonSharedFunds(): void
    {
        // arrange
        $metaCampaign = self::someMetaCampaign(isRegularGiving: false, isEmergencyIMF: false);
        $campaign = self::someCampaign(metaCampaignSlug: $metaCampaign->getSlug());

        // act
        $renderedCampaign = $this->SUT->renderCampaign($campaign, $metaCampaign);

        // assert
        // Because the parent i.e. metacampaign is regular giving funds will be shared with any other charity campaigns.
        $this->assertFalse($renderedCampaign['parentUsesSharedFunds']);
    }

    /** @dataProvider targetDataProvider */
    public function testTarget(
        bool $metaCampaignIsEmergencyIMF,
        bool $isMatched,
        int  $matchFundsTotal,
        int  $totalFundraisingTarget,
        int  $amountPledged,
        int  $totalFundingAllocation,
        int  $expectedTarget
    ): void {
        $metaCampaign = TestCase::someMetaCampaign(
            isRegularGiving: false,
            isEmergencyIMF: $metaCampaignIsEmergencyIMF,
        );
        $metaCampaign->setId(1); // id doesn't matter;
        $this->metaCampaignRepositoryProphecy->matchFundsTotal($metaCampaign)
            ->willReturn(Money::fromPence($matchFundsTotal, Currency::GBP));

        $campaign = self::someCampaign(
            isMatched: $isMatched,
            totalFundraisingTarget: Money::fromPence($totalFundraisingTarget, Currency::GBP),
            amountPledged: Money::fromPence($amountPledged, Currency::GBP),
            totalFundingAllocation: Money::fromPence($totalFundingAllocation, Currency::GBP),
            metaCampaignSlug: $metaCampaign->getSlug(),
        );

        $target = $this->SUT->campaignTarget($campaign, $metaCampaign);

        $this->assertEquals(Money::fromPence($expectedTarget, Currency::GBP), $target);
    }

    /**
     * @return array<string, array{0: bool, 1: bool, 2: int, 3: int, 4: int, 5: int}>
     */
    public function targetDataProvider(): array
    {
        // all amounts in pence
        //
        //   $metaCampaignIsEmergencyIMF, $isMatched,
        //   $matchFundsTotal, $totalFundraisingTarget, $amountPledged, $totalFundingAllocation,
        //   $expectedTarget
        return [
            'nothing will come of nothing' => [
                false, false,
                0_00, 0_00, 0_00, 0_00,
                0_00
            ],
            'uses emergency meta-campaign target' => [
                true, false,
                28_00, 0_00, 0_00, 0_00,
                56_00
            ],
            'uses totalFundRaisingTarget for non-emergency target' => [
                false, false,
                6_00, 12_35, 0_00, 0_00,
                12_35
            ],
            'for matched campaign, uses double sum of pledges and funding' => [
                false, true,
                6, 0_00, 150_00, 50_00, // unusual case having 150 and 50 not equal, but covers general case.
                400_00,
            ],
        ];
    }
}
