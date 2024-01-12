<?php

namespace MatchBot\IntegrationTests;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Charity;
use MatchBot\Tests\TestCase;

class CampaignRepositoryTest extends IntegrationTest
{
    public function testItFindsATwoWeekOldCampaignForACharityAwaitingGiftAidApproval(): void
    {
        // arrange
        $sut = $this->getService(CampaignRepository::class);

        $campaign = new Campaign($this->getCharityAwaitingGiftAidApproval());
        $campaign->setIsMatched(true);
        $campaign->setName('Campaign Name');
        $campaign->setCurrencyCode('GBP');
        $campaign->setStartDate(new \DateTimeImmutable('-4 weeks'));
        $campaign->setEndDate(new \DateTimeImmutable('-2 weeks'));

        $em = $this->getService(EntityManagerInterface::class);
        $em->persist($campaign);
        $em->flush();

        $newCampaignId = $campaign->getId();

        // act
        $campaignsFromDB = $sut->findRecentLiveAndPendingGiftAidApproval();

        // assert

        // We don't clear past data or isolate integration tests', so it is likely that there are other campaigns
        // in this list too.
        $idCriterion = Criteria::create()->where(Criteria::expr()->eq('id', $newCampaignId));
        $campaignsMatchingFixture = (new ArrayCollection($campaignsFromDB))->matching($idCriterion);

        $this->assertGreaterThanOrEqual(1, count($campaignsFromDB));
        $this->assertCount(1, $campaignsMatchingFixture);
        $this->assertSame($campaign, $campaignsMatchingFixture->first());
        $firstCampaign = $campaignsMatchingFixture->first();
        Assertion::isInstanceOf($firstCampaign, Campaign::class);
        $this->assertSame('Charity Name', $firstCampaign->getCharity()->getName());
    }

    public function testItFindsNoTenWeekOldCampaignEvenIfCharityAwaitingGiftAidApproval(): void
    {
        // arrange
        $sut = $this->getService(CampaignRepository::class);

        $campaign = new Campaign($this->getCharityAwaitingGiftAidApproval());
        $campaign->setIsMatched(true);
        $campaign->setName('Campaign Name');
        $campaign->setCurrencyCode('GBP');
        $campaign->setStartDate(new \DateTimeImmutable('-12 weeks'));
        $campaign->setEndDate(new \DateTimeImmutable('-10 weeks'));

        $em = $this->getService(EntityManagerInterface::class);
        $em->persist($campaign);
        $em->flush();

        $newCampaignId = $campaign->getId();

        // act
        $campaignsFromDB = $sut->findRecentLiveAndPendingGiftAidApproval();

        // assert

        // We don't clear past data or isolate integration tests', so it is likely that there are other campaigns
        // in this list too.
        $idCriterion = Criteria::create()->where(Criteria::expr()->eq('id', $newCampaignId));
        $campaignsMatchingFixture = (new ArrayCollection($campaignsFromDB))->matching($idCriterion);

        $this->assertCount(0, $campaignsMatchingFixture);
    }

    private function getCharityAwaitingGiftAidApproval(): Charity
    {
        $charity = TestCase::someCharity();
        $charity->setTbgClaimingGiftAid(true);
        $charity->setTbgApprovedToClaimGiftAid(false);

        return $charity;
    }
}
