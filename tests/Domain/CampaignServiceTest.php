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
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Contracts\Cache\CacheInterface;

class CampaignServiceTest extends TestCase
{
    private CampaignService $SUT;

    #[\Override]
    public function setUp(): void
    {
        parent::setUp();

        // having all these stubs here suggests probably this service class should be broken up so the part that
        // doesn't use the dependances can be tested separately.
        $this->SUT = new CampaignService(
            campaignRepository: $this->createStub(CampaignRepository::class),
            metaCampaignRepository: $this->createStub(MetaCampaignRepository::class),
            cache: $this->createStub(CacheInterface::class),
            donationRepository: $this->createStub(DonationRepository::class),
            matchFundsRemainingService: $this->createStub(MatchFundsService::class),
            log: $this->createStub(LoggerInterface::class),
            clock: new MockClock(new \DateTimeImmutable('1970-01-01')),
        );
    }

    public function testItRendersCampaignWithDetailsOfRelatedMetaCampaignWithSharedFunds(): void
    {
        // arrange
        $campaign = TestCase::someCampaign();
        $metaCampaign = $this->someMetaCampaign(isRegularGiving: true, isEmergencyIMF: false);

        // act
        $renderedCampaign = $this->SUT->renderCampaign($campaign, $metaCampaign);

        // assert
        // Because the parent i.e. metacampaign is regular giving funds will be shared with any other charity campaigns.
        $this->assertTrue($renderedCampaign['parentUsesSharedFunds']);
    }


    public function testItRendersCampaignWithDetailsOfRelatedMetaCampaignWithNonSharedFunds(): void
    {
        // arrange
        $campaign = TestCase::someCampaign();
        $metaCampaign = $this->someMetaCampaign(isRegularGiving: false, isEmergencyIMF: false);

        // act
        $renderedCampaign = $this->SUT->renderCampaign($campaign, $metaCampaign);

        // assert
        // Because the parent i.e. metacampaign is regular giving funds will be shared with any other charity campaigns.
        $this->assertFalse($renderedCampaign['parentUsesSharedFunds']);
    }

    public function someMetaCampaign(bool $isRegularGiving, bool $isEmergencyIMF): MetaCampaign
    {
        return new MetaCampaign(
            slug: MetaCampaignSlug::of('not-relevant'),
            salesforceId: Salesforce18Id::ofMetaCampaign('000000000000000000'),
            title: 'not relevant',
            currency: Currency::GBP,
            status: 'Active',
            hidden: false,
            summary: 'not relevant',
            bannerURI: null,
            startDate: new \DateTimeImmutable('1970'),
            endDate: new \DateTimeImmutable('1970'),
            isRegularGiving: $isRegularGiving,
            isEmergencyIMF: $isEmergencyIMF
        );
    }
}
