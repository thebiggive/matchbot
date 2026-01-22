<?php

namespace MatchBot\IntegrationTests;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use MatchBot\Domain\ApplicationStatus;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignStatistics;
use MatchBot\Domain\Charity;
use MatchBot\Domain\CharityResponseToOffer;
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
            summary: 'Campaign Summary',
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
            summary: 'Campaign Summary',
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

        $this->insertCampaignsForSearchToFind();

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

    /**
     * @param string $query A user search query
     * @param list<array{0: string, 1: string}> $expectedResultsWithOldSearch The results that our old search system will give, as a list of campaign and charity names
     * @param list<array{0: string, 1: string}> $expectedResultsWithNewSearch The results that our new search system will give, as a list of campaign and charity names
     *
     * @dataProvider searchQueriesAgainstResultsProvider
     *
     * @return void
     */
    public function testSearchQueriesAgainstResults(string $query, array $expectedResultsWithOldSearch, array $expectedResultsWithNewSearch)
    {
        $sut = $this->getService(CampaignRepository::class);

        $this->insertCampaignsForSearchToFind();

        // act
        $resultsWithOldSearch = $sut->search(
            sortField: 'relevance',
            sortDirection: 'desc',
            offset: 0,
            limit: 600,
            status: null,
            metaCampaignSlug: null,
            fundSlug: null,
            jsonMatchInListConditions: [
            ],
            term: $query,
            fulltext: false,
        );

        $oldSearchNames = array_map(fn(Campaign $campaign) => [$campaign->getCharity()->getName(), $campaign->getCampaignName()], $resultsWithOldSearch);

        $resultsWithNewSearch = $sut->search(
            sortField: 'relevance',
            sortDirection: 'desc',
            offset: 0,
            limit: 600,
            status: null,
            metaCampaignSlug: null,
            fundSlug: null,
            jsonMatchInListConditions: [
            ],
            term: $query,
            fulltext: true,
        );

        $newSearchNames = array_map(fn(Campaign $campaign) => [$campaign->getCharity()->getName(), $campaign->getCampaignName()], $resultsWithNewSearch);

        // assert

        $export = \var_export($oldSearchNames, true);
        $this->assertSame($expectedResultsWithOldSearch, $oldSearchNames, 'Old search results should match expecation: ' . $export);
        $this->assertSame($expectedResultsWithNewSearch, $newSearchNames, 'New search results should match expecation');
    }


    public function testNonFullTextSearchDoesNotTokenise(): void
    {
        // arrange
        $sut = $this->getService(CampaignRepository::class);

        $this->insertCampaignsForSearchToFind();


        // these words appear non-contiguously in our campaign name, so our current search would not find them
        // as it would just look for the exact phrase "Porridge Juice". The fulltext search automatically
        // tokenises on spaces and treats this as two search terms.
        $term = 'Porridge Juice';

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
            term: $term,
            fulltext: false,
        );

        // assert
        $this->assertEmpty($result);
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

    /**
     * @return void
     */
    public function insertCampaignsForSearchToFind(): void
    {

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
            summary: 'Campaign Summary',
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

        $stats1 = CampaignStatistics::zeroPlaceholder($campaign1, new \DateTimeImmutable('now'));
        $em = $this->getService(EntityManagerInterface::class);

        $em->persist($stats1);

        $em->persist($campaign1);

        foreach (
            [
            ['Charity Name', 'Campaign Two is for Porridge and Juice'],
            ['Fred\'s Charity', 'This is a campaign for Fred\'s Charity'],
            ['Fred\'s Charity', 'This is a campaign name that does not mention the charity name'],
                 ] as [$charityName, $campaignName]
        ) {
            $campaign = new Campaign(
                self::someSalesForce18CampaignId(),
                metaCampaignSlug: 'the-family',
                charity: TestCase::someCharity(name: $charityName),
                startDate: new \DateTimeImmutable('-8 months'),
                endDate: new \DateTimeImmutable('+1 month'),
                isMatched: true,
                ready: true,
                status: 'Active',
                name: $campaignName,
                summary: 'Campaign Summary',
                currencyCode: 'GBP',
                totalFundingAllocation: Money::zero(),
                amountPledged: Money::zero(),
                isRegularGiving: false,
                pinPosition: null,
                championPagePinPosition: null,
                relatedApplicationStatus: ApplicationStatus::Approved,
                relatedApplicationCharityResponseToOffer: CharityResponseToOffer::Accepted,
                regularGivingCollectionEnd: null,
                totalFundraisingTarget: Money::zero(),
                thankYouMessage: null,
                rawData: [
                    'beneficiaries' => ['Lads', 'Dads'],
                    'categories' => ['Food', 'Drink'],
                    'countries' => ['United Kingdom', 'Ireland'],
                    'title' => $campaignName,
                ],
                hidden: false,
            );

            // Add empty initial stats
            $stats2 = CampaignStatistics::zeroPlaceholder($campaign, new \DateTimeImmutable('now'));

            $em->persist($campaign);
            $em->persist($stats2);
        }


        $em->flush();
    }

    /**
     * @return array<array-key, array{
     *     0: string,
     *     1: list<array{0: string, 1: string}>,
     *     2: list<array{0: string, 1: string}>
     *   }>
     */
    public function searchQueriesAgainstResultsProvider(): array
    {
        return [
            [
                'Porridge and Juice',
                [['Charity Name', 'Campaign Two is for Porridge and Juice']],
                [['Charity Name', 'Campaign Two is for Porridge and Juice']],
            ],
            [
                'Charity',
                [

                    [
                        'Charity Name',
                        'Campaign Two is for Porridge and Juice',
                    ],
                    [
                        'Fred\'s Charity',
                        'This is a campaign for Fred\'s Charity',
                    ],
                    ['Fred\'s Charity', 'This is a campaign name that does not mention the charity name'],
                ],
                [
                    ['Fred\'s Charity', 'This is a campaign for Fred\'s Charity'],
                    ['Fred\'s Charity', 'This is a campaign name that does not mention the charity name'],
                    ['Charity Name', 'Campaign Two is for Porridge and Juice'],
                ],
            ],
            [
                'Porridge Juice', // searching for words that are non-contiguous in the result
                [],
                [['Charity Name', 'Campaign Two is for Porridge and Juice']]
            ],
            [
                'Fred\'s Charity',
                [

                    ['Fred\'s Charity', 'This is a campaign for Fred\'s Charity'],
                    ['Fred\'s Charity', 'This is a campaign name that does not mention the charity name'],
                ],
                [
                    ['Fred\'s Charity', 'This is a campaign for Fred\'s Charity'],
                    ['Fred\'s Charity', 'This is a campaign name that does not mention the charity name'],
                    ['Charity Name', 'Campaign Two is for Porridge and Juice'],
                ]
            ],
            [
                'Freds Charity',
                [],
                [
                    ['Fred\'s Charity', 'This is a campaign for Fred\'s Charity'],
                    ['Fred\'s Charity', 'This is a campaign name that does not mention the charity name'],
                    ['Charity Name', 'Campaign Two is for Porridge and Juice'],
                ]
            ],
            [
                'Fred',
                [
                    ['Fred\'s Charity', 'This is a campaign for Fred\'s Charity'],
                    ['Fred\'s Charity', 'This is a campaign name that does not mention the charity name'],
                ],
                [
                    // the new search index stores "Freds" not "Fred's" and does not match on substrings so does
                    // not find anything here.
                ]
            ],
            [
                'Freds',
                [
                ],
                [
                    ['Fred\'s Charity', 'This is a campaign for Fred\'s Charity'],
                    ['Fred\'s Charity', 'This is a campaign name that does not mention the charity name'],
                ]
            ],
            [
                'Porridge WORDTHATDOESNOTEXIST',
                [
                ],
                [
                    ['Charity Name', 'Campaign Two is for Porridge and Juice'],
                ]
            ],
        ];
    }
}
