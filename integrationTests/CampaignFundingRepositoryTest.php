<?php

namespace MatchBot\IntegrationTests;

use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundType;
use MatchBot\Domain\MetaCampaign;
use MatchBot\Domain\Money;
use MatchBot\Tests\TestCase;

class CampaignFundingRepositoryTest extends IntegrationTest
{
    private CampaignFundingRepository $sut;
    private MetaCampaign $metaCampaign;

    /**
     * @param CampaignFunding $campaignFunding
     * @return void
     * @throws \Doctrine\ORM\Exception\ORMException
     */
    public function addCampaignSharingFunding(CampaignFunding $campaignFunding): void
    {
        $campaign = TestCase::someCampaign(metaCampaignSlug: $this->metaCampaign->getSlug());
        $campaignFunding->addCampaign($campaign);
        $this->em->persist($campaign);
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->metaCampaign = TestCase::someMetaCampaign(isRegularGiving: true, isEmergencyIMF: false);
        $this->em->persist($this->metaCampaign);
        $this->sut = $this->getService(CampaignFundingRepository::class);

        $this->em->flush();
    }

    public function testItCountsTotalOnceForSharedAvailableFunds(): void
    {
        // arrange
        $fund = new Fund('GBP', 'any fund', 'slug', null, FundType::ChampionFund);
        $this->em->persist($fund);

        $campaignFunding = new CampaignFunding($fund, '20000.00', '20000.00');
        $this->em->persist($campaignFunding);

        $this->addCampaignSharingFunding($campaignFunding);
        $this->addCampaignSharingFunding($campaignFunding);
        $this->addCampaignSharingFunding($campaignFunding);
        $this->addCampaignSharingFunding($campaignFunding);
        $this->addCampaignSharingFunding($campaignFunding);

        $this->em->flush();

        // act
        $amountAvailableForMetaCampaign = $this->sut->getAmountAvailableForMetaCampaign($this->metaCampaign);

        // assert
        $this->assertEquals(
            Money::fromPoundsGBP(20_000),
            $amountAvailableForMetaCampaign
        );
    }
}
