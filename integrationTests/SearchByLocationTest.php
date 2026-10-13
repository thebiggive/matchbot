<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\ApplicationStatus;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignStatistics;
use MatchBot\Domain\CampaignStatus;
use MatchBot\Domain\CharityResponseToOffer;
use MatchBot\IntegrationTests\IntegrationTest;
use MatchBot\Tests\TestCase;

/**
 * Test scenarios relating to searching for campaigns by location of impact
 */
class SearchByLocationTest extends IntegrationTest
{
    private const string CAMPAIGN_FOR_CAMDEN_NAME = 'Campaign For Camden';
    private const string CAMPAIGN_FOR_HARINGEY_NAME = 'Campaign For Haringey';
    private const string CAMPAIGN_FOR_LONDON_NAME = 'Campaign For London';
    private const string CAMPAIGN_FOR_UK_NAME = 'Campaign For UK';
    private const string REGION_CODE_HARINGEY = 'E09000014';
    private const string REGION_CODE_CAMDEN = 'E09000007';
    private const string REGION_CODE_LONDON = 'E12000007';
    private const string REGION_CODE_ENGLAND = 'E92000001';

    public function testSearchSortsByLocation(): void
    {
        $sut = $this->getService(CampaignRepository::class);
        $em = $this->getService(EntityManagerInterface::class);

        $charity = TestCase::someCharity();

        $slug = self::randomSlug();

        $UKCampaign = $this->createCampaign(
            charity: $charity,
            name: self::CAMPAIGN_FOR_UK_NAME,
            status: CampaignStatus::Active,
            withUniqueSalesforceId: true,
            metaCampaignSlug: $slug,
            relatedApplicationStatus: ApplicationStatus::Approved,
            relatedApplicationCharityResponseToOffer: CharityResponseToOffer::Accepted
        );

        $UKCampaign->replaceLocations([
            ['countryName' => 'uk', 'regionCode' => null],
        ]);
        $stats = CampaignStatistics::zeroPlaceholder($UKCampaign, new \DateTimeImmutable('now'));

        $em->persist($UKCampaign);
        $em->persist($stats);

        $londonCampaign = $this->createCampaign(
            charity: $charity,
            name: self::CAMPAIGN_FOR_LONDON_NAME,
            status: CampaignStatus::Active,
            withUniqueSalesforceId: true,
            metaCampaignSlug: $slug,
            relatedApplicationStatus: ApplicationStatus::Approved,
            relatedApplicationCharityResponseToOffer: CharityResponseToOffer::Accepted
        );

        $londonCampaign->replaceLocations([
            ['countryName' => 'uk', 'regionCode' => null],
            ['countryName' => null, 'regionCode' => self::REGION_CODE_LONDON],
        ]);
        $stats = CampaignStatistics::zeroPlaceholder($londonCampaign, new \DateTimeImmutable('now'));
        $em->persist($stats);

        $em->persist($londonCampaign);

        $haringeyCampaign = $this->createCampaign(
            charity: $charity,
            name: self::CAMPAIGN_FOR_HARINGEY_NAME,
            status: CampaignStatus::Active,
            withUniqueSalesforceId: true,
            metaCampaignSlug: $slug,
            relatedApplicationStatus: ApplicationStatus::Approved,
            relatedApplicationCharityResponseToOffer: CharityResponseToOffer::Accepted
        );

        $haringeyCampaign->replaceLocations([
            ['countryName' => 'uk', 'regionCode' => null],
            ['countryName' => null, 'regionCode' => self::REGION_CODE_HARINGEY],
        ]);
        $stats = CampaignStatistics::zeroPlaceholder($haringeyCampaign, new \DateTimeImmutable('now'));

        $em->persist($haringeyCampaign);

        $em->persist($stats);

        $camdenCampaign = $this->createCampaign(
            charity: $charity,
            name: self::CAMPAIGN_FOR_CAMDEN_NAME,
            status: CampaignStatus::Active,
            withUniqueSalesforceId: true,
            metaCampaignSlug: $slug,
            relatedApplicationStatus: ApplicationStatus::Approved,
            relatedApplicationCharityResponseToOffer: CharityResponseToOffer::Accepted
        );

        $camdenCampaign->replaceLocations([
            ['countryName' => 'uk', 'regionCode' => null],
            ['countryName' => null, 'regionCode' => self::REGION_CODE_CAMDEN],
        ]);
        $stats = CampaignStatistics::zeroPlaceholder($camdenCampaign, new \DateTimeImmutable('now'));

        $em->persist($camdenCampaign);

        $em->persist($stats);

        $em->flush();

        $returnValue = $sut->search(
            sortField: 'location',
            sortDirection: 'desc',
            offset: 0,
            limit: 6,
            metaCampaignSlug: $slug,
            fundSlug: null,
            jsonMatchInListConditions: [],
            term: null,
            country: null,
            regions: [self::REGION_CODE_HARINGEY, self::REGION_CODE_LONDON, self::REGION_CODE_ENGLAND],
            forInternalUpdate: false,
            filterByRegions: false,
        )->campaigns;

        $returnCampaignNames = array_map(
            static fn(Campaign $campaign) => $campaign->getCampaignName(),
            $returnValue
        );

        $this->assertSame([
            self::CAMPAIGN_FOR_HARINGEY_NAME,
            self::CAMPAIGN_FOR_LONDON_NAME,
            self::CAMPAIGN_FOR_UK_NAME,
            self::CAMPAIGN_FOR_CAMDEN_NAME // camden doesn't match any of the user's search regions, so would rank equal to UK campaign, then ranks lower because it has a higher auto increment ID.
        ], $returnCampaignNames);
    }

    public function testSearchFilterByRegionMatchesDescendantLocations(): void
    {
        $sut = $this->getService(CampaignRepository::class);
        $em = $this->getService(EntityManagerInterface::class);

        $charity = TestCase::someCharity();
        $slug = self::randomSlug();

        // East of England region
        $eastOfEnglandCampaign = $this->createCampaign(
            charity: $charity,
            name: 'Campaign For East of England',
            status: CampaignStatus::Active,
            withUniqueSalesforceId: true,
            metaCampaignSlug: $slug,
            relatedApplicationStatus: ApplicationStatus::Approved,
            relatedApplicationCharityResponseToOffer: CharityResponseToOffer::Accepted
        );
        $eastOfEnglandCampaign->replaceLocations([
            ['countryName' => 'uk', 'regionCode' => 'E12000006'], // East of England
        ]);
        $em->persist($eastOfEnglandCampaign);
        $em->persist(CampaignStatistics::zeroPlaceholder($eastOfEnglandCampaign, new \DateTimeImmutable('now')));

        // Cambridgeshire (descendant of East of England)
        $cambridgeshireCampaign = $this->createCampaign(
            charity: $charity,
            name: 'Campaign For Cambridgeshire',
            status: CampaignStatus::Active,
            withUniqueSalesforceId: true,
            metaCampaignSlug: $slug,
            relatedApplicationStatus: ApplicationStatus::Approved,
            relatedApplicationCharityResponseToOffer: CharityResponseToOffer::Accepted
        );
        $cambridgeshireCampaign->replaceLocations([
            ['countryName' => 'uk', 'regionCode' => 'E10000003'], // Cambridgeshire
        ]);
        $em->persist($cambridgeshireCampaign);
        $em->persist(CampaignStatistics::zeroPlaceholder($cambridgeshireCampaign, new \DateTimeImmutable('now')));

        // Huntingdonshire (descendant of Cambridgeshire & East of England)
        $huntingdonshireCampaign = $this->createCampaign(
            charity: $charity,
            name: 'Campaign For Huntingdonshire',
            status: CampaignStatus::Active,
            withUniqueSalesforceId: true,
            metaCampaignSlug: $slug,
            relatedApplicationStatus: ApplicationStatus::Approved,
            relatedApplicationCharityResponseToOffer: CharityResponseToOffer::Accepted
        );
        $huntingdonshireCampaign->replaceLocations([
            ['countryName' => 'uk', 'regionCode' => 'E07000011'], // Huntingdonshire
        ]);
        $em->persist($huntingdonshireCampaign);
        $em->persist(CampaignStatistics::zeroPlaceholder($huntingdonshireCampaign, new \DateTimeImmutable('now')));

        // London campaign (outside East of England)
        $londonCampaign = $this->createCampaign(
            charity: $charity,
            name: 'Campaign For London',
            status: CampaignStatus::Active,
            withUniqueSalesforceId: true,
            metaCampaignSlug: $slug,
            relatedApplicationStatus: ApplicationStatus::Approved,
            relatedApplicationCharityResponseToOffer: CharityResponseToOffer::Accepted
        );
        $londonCampaign->replaceLocations([
            ['countryName' => 'uk', 'regionCode' => self::REGION_CODE_LONDON],
        ]);
        $em->persist($londonCampaign);
        $em->persist(CampaignStatistics::zeroPlaceholder($londonCampaign, new \DateTimeImmutable('now')));

        $em->flush();

        // Search filtering by East of England
        $result = $sut->search(
            sortField: 'amountRaised',
            sortDirection: 'asc',
            offset: 0,
            limit: 10,
            metaCampaignSlug: $slug,
            fundSlug: null,
            jsonMatchInListConditions: [],
            term: null,
            country: null,
            regions: ['E12000006'],
            forInternalUpdate: false,
            filterByRegions: true,
        );

        $campaignNames = array_map(
            static fn(Campaign $campaign) => $campaign->getCampaignName(),
            $result->campaigns
        );

        $this->assertContains('Campaign For East of England', $campaignNames);
        $this->assertContains('Campaign For Cambridgeshire', $campaignNames);
        $this->assertContains('Campaign For Huntingdonshire', $campaignNames);
        $this->assertNotContains('Campaign For London', $campaignNames);

        // Check location counts
        $locationCountsMap = [];
        foreach ($result->locationCounts as $lc) {
            $locationCountsMap[$lc['regionCode']] = $lc['numCampaigns'];
        }

        // East of England summary count should include all 3 campaigns in East of England and its descendants
        $this->assertSame(3, $locationCountsMap['E12000006']);
        // London summary count should be 1
        $this->assertSame(1, $locationCountsMap[self::REGION_CODE_LONDON]);

        // Search filtering by Cambridgeshire (descendant of East of England, parent of Huntingdonshire)
        $cambridgeshireResult = $sut->search(
            sortField: 'amountRaised',
            sortDirection: 'asc',
            offset: 0,
            limit: 10,
            metaCampaignSlug: $slug,
            fundSlug: null,
            jsonMatchInListConditions: [],
            term: null,
            country: null,
            regions: ['E10000003'],
            forInternalUpdate: false,
            filterByRegions: true,
        );

        $cambridgeshireCampaignNames = array_map(
            static fn(Campaign $campaign) => $campaign->getCampaignName(),
            $cambridgeshireResult->campaigns
        );

        $this->assertContains('Campaign For Cambridgeshire', $cambridgeshireCampaignNames);
        $this->assertContains('Campaign For Huntingdonshire', $cambridgeshireCampaignNames);
        $this->assertNotContains('Campaign For East of England', $cambridgeshireCampaignNames);
        $this->assertNotContains('Campaign For London', $cambridgeshireCampaignNames);

        $cambsLocationCountsMap = [];
        foreach ($cambridgeshireResult->locationCounts as $lc) {
            $cambsLocationCountsMap[$lc['regionCode']] = $lc['numCampaigns'];
        }

        // Cambridgeshire count includes Cambridgeshire + Huntingdonshire = 2
        $this->assertSame(2, $cambsLocationCountsMap['E10000003']);
        // Huntingdonshire child count = 1
        $this->assertSame(1, $cambsLocationCountsMap['E07000011']);
    }
}
