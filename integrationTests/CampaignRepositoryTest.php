<?php

namespace MatchBot\IntegrationTests;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignStatistics;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Money;
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
            metaCampaignSlug: null,
            charity: $this->getCharityAwaitingGiftAidApproval(),
            startDate: new \DateTimeImmutable('-10 months'), // less than the 9 month limit
            endDate: new \DateTimeImmutable(-29 * 9 . 'days'),
            isMatched: true,
            ready: true,
            status: null,
            name: 'Campaign Name',
            currencyCode: 'GBP',
            totalFundingAllocation: Money::zero(),
            amountPledged: Money::zero(),
            isRegularGiving: false,
            pinPosition: null,
            championPagePinPosition: null,
            relatedApplicationStatus: null,
            relatedApplicationCharityResponseToOffer: null,
            regularGivingCollectionEnd: null,
            totalFundraisingTarget: Money::zero(),
            thankYouMessage: null,
            rawData: [],
            hidden: false
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
            metaCampaignSlug: null,
            charity: $this->getCharityAwaitingGiftAidApproval(),
            startDate: new \DateTimeImmutable('-11 months'),
            endDate: new \DateTimeImmutable('-10 months'),
            isMatched: true,
            ready: true,
            status: null,
            name: 'Campaign Name',
            currencyCode: 'GBP',
            totalFundingAllocation: Money::zero(),
            amountPledged: Money::zero(),
            isRegularGiving: false,
            pinPosition: null,
            championPagePinPosition: null,
            relatedApplicationStatus: null,
            relatedApplicationCharityResponseToOffer: null,
            regularGivingCollectionEnd: null,
            thankYouMessage: null,
            rawData: [],
            hidden: false,
            totalFundraisingTarget: Money::zero(),
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

    public function testSearchWithVariousFilters(): void
    {
        // arrange
        $sut = $this->getService(CampaignRepository::class);

        $campaign1 = new Campaign(
            self::someSalesForce18CampaignId(),
            metaCampaignSlug: null,
            charity: TestCase::someCharity(),
            startDate: new \DateTimeImmutable('-10 months'),
            endDate: new \DateTimeImmutable('-9 months'),
            isMatched: true,
            ready: true,
            status: null,
            name: 'Campaign One',
            currencyCode: 'GBP',
            totalFundingAllocation: Money::zero(),
            amountPledged: Money::zero(),
            isRegularGiving: false,
            pinPosition: null,
            championPagePinPosition: null,
            relatedApplicationStatus: null,
            relatedApplicationCharityResponseToOffer: null,
            regularGivingCollectionEnd: null,
            totalFundraisingTarget: Money::zero(),
            thankYouMessage: null,
            rawData: [],
            hidden: false,
        );

        $campaign2 = new Campaign(
            self::someSalesForce18CampaignId(),
            metaCampaignSlug: 'the-family',
            charity: TestCase::someCharity(),
            startDate: new \DateTimeImmutable('-8 months'),
            endDate: new \DateTimeImmutable('+1 month'),
            isMatched: true,
            ready: true,
            status: 'Active',
            name: 'Campaign Two is for Porridge and Juice',
            currencyCode: 'GBP',
            totalFundingAllocation: Money::zero(),
            amountPledged: Money::zero(),
            isRegularGiving: false,
            pinPosition: null,
            championPagePinPosition: null,
            relatedApplicationStatus: 'Approved',
            relatedApplicationCharityResponseToOffer: 'Accepted',
            regularGivingCollectionEnd: null,
            totalFundraisingTarget: Money::zero(),
            thankYouMessage: null,
            rawData: [
                'beneficiaries' => ['Lads', 'Dads'],
                'categories' => ['Food', 'Drink'],
                'countries' => ['United Kingdom', 'Ireland'],
                'title' => 'Campaign Two is for Porridge and Juice',
            ],
            hidden: false,
        );

        // Add empty initial stats
        $stats1 = CampaignStatistics::zeroPlaceholder($campaign1, new \DateTimeImmutable('now'));
        $stats2 = CampaignStatistics::zeroPlaceholder($campaign2, new \DateTimeImmutable('now'));

        $em = $this->getService(EntityManagerInterface::class);
        $em->persist($campaign1);
        $em->persist($campaign2);
        $em->persist($stats1);
        $em->persist($stats2);
        $em->flush();

        // act
        $result = $sut->search(
            sortField: 'relevance',
            sortDirection: 'desc',
            offset: 0,
            limit: 6,
            status: 'Active',
            metaCampaignSlug: 'the-family',
            fundSlug: null,
            jsonMatchInListConditions: [
                'beneficiaries' => 'Lads',
                'categories' => 'Food',
                'countries' => 'United Kingdom'
            ],
            term: 'Porridge',
        );

        // assert
        $this->assertCount(1, $result);
        $this->assertSame('Campaign Two is for Porridge and Juice', $result[0]->getCampaignName());
    }

    public function testSearchSortsByStatus(): void
    {
        $sut = $this->getService(CampaignRepository::class);
        $em = $this->getService(EntityManagerInterface::class);

        $charity = TestCase::someCharity();

        foreach (['Expired', 'Active', 'Preview'] as $status) {
            $campaign = $this->createCampaign(
                charity: $charity,
                name: 'Campaign ' . $status,
                status: $status,
                withUniqueSalesforceId: true,
            );
            $stats = CampaignStatistics::zeroPlaceholder($campaign, new \DateTimeImmutable('now'));
            $em->persist($campaign);
            $em->persist($stats);
        }
        $em->flush();

        $returnValue = $sut->search(
            sortField: 'distanceToTarget',
            sortDirection: 'desc',
            offset: 0,
            limit: 6,
            status: null,
            metaCampaignSlug: null,
            fundSlug: null,
            jsonMatchInListConditions: [],
            term: null,
        );

        $returnCampaignNames = array_map(
            static fn(Campaign $campaign) => $campaign->getCampaignName(),
            $returnValue
        );

        // Expired is excluded from the Explore list with no metacampaign slug.
        $this->assertSame(['Campaign Active', 'Campaign Preview'], $returnCampaignNames);
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
