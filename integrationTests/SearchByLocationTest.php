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
            regions: [self::REGION_CODE_HARINGEY, self::REGION_CODE_LONDON, self::REGION_CODE_ENGLAND],
            offset: 0,
            limit: 6,
            metaCampaignSlug: $slug,
            fundSlug: null,
            jsonMatchInListConditions: [],
            term: null,
        );

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
}
