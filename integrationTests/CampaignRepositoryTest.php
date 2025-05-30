<?php

namespace MatchBot\IntegrationTests;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;
use Random\Randomizer;

class CampaignRepositoryTest extends IntegrationTest
{
    public function testItFindsANineMonthOldCampaignForACharityAwaitingGiftAidApproval(): void
    {
        // arrange
        $sut = $this->getService(CampaignRepository::class);

        $campaign = new Campaign(
            $this->randomCampaignId(),
            $this->getCharityAwaitingGiftAidApproval(),
            startDate: new \DateTimeImmutable('-10 months'),
            endDate: new \DateTimeImmutable(-29 * 9 . 'days'), // less than the 9 month limit
            isMatched: true,
            ready: true,
            status: null,
            name: 'Campaign Name',
            currencyCode: 'GBP',
            isRegularGiving: false,
            regularGivingCollectionEnd: null,
        );


        $em = $this->getService(EntityManagerInterface::class);
        $em->persist($campaign);
        $em->flush();

        $newCampaignId = $campaign->getId();

        // act
        $campaignsFromDB = $sut->findCampaignsThatNeedToBeUpToDate();

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

    public function testItFindsNo10MonthOldCampaignEvenIfCharityAwaitingGiftAidApproval(): void
    {
        // arrange
        $sut = $this->getService(CampaignRepository::class);

        $campaign = new Campaign(
            self::someSalesForce18CampaignId(),
            $this->getCharityAwaitingGiftAidApproval(),
            startDate: new \DateTimeImmutable('-11 months'),
            endDate: new \DateTimeImmutable('-10 months'),
            isMatched: true,
            ready: true,
            status: null,
            name: 'Campaign Name',
            currencyCode: 'GBP',
            isRegularGiving: false,
            regularGivingCollectionEnd: null,
        );

        $em = $this->getService(EntityManagerInterface::class);
        $em->persist($campaign);
        $em->flush();

        $newCampaignId = $campaign->getId();

        // act
        $campaignsFromDB = $sut->findCampaignsThatNeedToBeUpToDate();

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

    /**
     * @return Salesforce18Id<Campaign>
     */
    public function randomCampaignId(): Salesforce18Id
    {
        $id = (new Randomizer())->getBytesFromString('abcdef01234567890', 18);
        return Salesforce18Id::ofCampaign($id);
    }
}
