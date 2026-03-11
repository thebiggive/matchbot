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

        $this->metaCampaignRepositoryProphecy = $this->prophesize(MetaCampaignRepository::class);

        $this->SUT = new CampaignService(
            campaignRepository: $this->createStub(CampaignRepository::class),
            metaCampaignRepository: $this->metaCampaignRepositoryProphecy->reveal(),
            cache: new NullAdapter(),
            donationRepository: $this->createStub(DonationRepository::class),
            matchFundsService: $this->getMatchFundsService(Money::zero(), Money::zero()),
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
        int $metaCampaignMatchFundsTotal,
        int $thisCampaignMatchFundsTotal,
        int $totalFundraisingTarget,
        int $expectedTarget
    ): void {
        $metaCampaignMoney = Money::fromPence($metaCampaignMatchFundsTotal, Currency::GBP);
        $this->SUT = new CampaignService(
            campaignRepository: $this->createStub(CampaignRepository::class),
            metaCampaignRepository: $this->metaCampaignRepositoryProphecy->reveal(),
            cache: new NullAdapter(),
            donationRepository: $this->createStub(DonationRepository::class),
            matchFundsService: $this->getMatchFundsService(
                totalForMetacampaign: $metaCampaignMoney,
                availableForMetacampaign: $metaCampaignMoney,
            ),
            log: $this->createStub(LoggerInterface::class),
            clock: new MockClock(new \DateTimeImmutable('1970-01-01')),
        );

        $metaCampaign = TestCase::someMetaCampaign(
            isRegularGiving: false,
            isEmergencyIMF: $metaCampaignIsEmergencyIMF,
        );
        $metaCampaign->setId(1); // id doesn't matter;

        $campaign = self::someCampaign(
            metaCampaignSlug: $metaCampaign->getSlug(),
            isMatched: $isMatched,
            totalFundraisingTarget: Money::fromPence($totalFundraisingTarget, Currency::GBP),
            withMatchFundsTotal: Money::fromPence($thisCampaignMatchFundsTotal, Currency::GBP),
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
        //   $metaCampaignMatchFundsTotal, $thisCampaignMatchFundsTotal, $totalFundraisingTarget,
        //   $expectedTarget
        return [
            'nothing will come of nothing' => [
                false, false,
                0_00, 0_00, 0_00,
                0_00
            ],
            'uses emergency meta-campaign target' => [
                true, false,
                28_00, 0_00, 0_00,
                56_00
            ],
            'uses totalFundRaisingTarget for unmatched campaign target' => [
                false, false,
                6_00, 0_00, 12_35,
                12_35
            ],
            'for matched campaign, uses own campaign\'s funding' => [
                false, true,
                0_00, 499_00, 200_00, // Simulate e.g. out of sync target from SF
                998_00,
            ],
        ];
    }

    private function getMatchFundsService(Money $totalForMetacampaign, Money $availableForMetacampaign): MatchFundsService
    {
        $matchFundsServiceProphecy = $this->prophesize(MatchFundsService::class);
        $matchFundsServiceProphecy->getFundsTotalForMetaCampaign(Argument::any())->willReturn($totalForMetacampaign);
        $matchFundsServiceProphecy->getFundsRemainingForMetaCampaign(Argument::any())->willReturn($availableForMetacampaign);

        return $matchFundsServiceProphecy->reveal();
    }
}
