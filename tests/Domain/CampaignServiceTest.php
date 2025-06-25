<?php

namespace Domain;

use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignService;
use MatchBot\Domain\Currency;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\MatchFundsService;
use MatchBot\Domain\MetaCampaign;
use MatchBot\Domain\MetaCampaignRepository;
use MatchBot\Domain\MetaCampaignSlug;
use MatchBot\Domain\Money;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Cache\CacheInterface;

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
            matchFundsRemainingService: $matchFundsServiceProphecy->reveal(),
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
        $this->assertSame(12, $renderedCampaign['parentAmountRaised']);
        $this->assertSame(3, $renderedCampaign['parentDonationCount']);
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
}
