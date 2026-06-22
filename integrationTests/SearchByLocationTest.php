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
    public function testSearchSortsByLocation(): void
    {
        $sut = $this->getService(CampaignRepository::class);
        $em = $this->getService(EntityManagerInterface::class);

        $charity = TestCase::someCharity();

        $slug = self::randomSlug();

        $UKCampaign = $this->createCampaign(
            charity: $charity,
            name: 'Campaign For UK',
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
            name: 'Campaign For London',
            status: CampaignStatus::Active,
            withUniqueSalesforceId: true,
            metaCampaignSlug: $slug,
            relatedApplicationStatus: ApplicationStatus::Approved,
            relatedApplicationCharityResponseToOffer: CharityResponseToOffer::Accepted
        );

        $londonCampaign->replaceLocations([
            ['countryName' => 'uk', 'regionCode' => null],
            ['countryName' => null, 'regionCode' => 'E12000007'],
        ]);
        $stats = CampaignStatistics::zeroPlaceholder($londonCampaign, new \DateTimeImmutable('now'));
        $em->persist($stats);

        $em->persist($londonCampaign);

        $haringeyCampaign = $this->createCampaign(
            charity: $charity,
            name: 'Campaign For Haringey',
            status: CampaignStatus::Active,
            withUniqueSalesforceId: true,
            metaCampaignSlug: $slug,
            relatedApplicationStatus: ApplicationStatus::Approved,
            relatedApplicationCharityResponseToOffer: CharityResponseToOffer::Accepted
        );

        $haringeyCampaign->replaceLocations([
            ['countryName' => 'uk', 'regionCode' => null],
            ['countryName' => null, 'regionCode' => 'E09000014'],
        ]);
        $stats = CampaignStatistics::zeroPlaceholder($haringeyCampaign, new \DateTimeImmutable('now'));

        $em->persist($haringeyCampaign);

        $em->persist($stats);

        $em->flush();

        $returnValue = $sut->search(
            sortField: 'location',
            sortDirection: 'desc',
            regions: ['E09000014','E12000007', 'E92000001'],
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

        $this->assertSame(['Campaign For Haringey', 'Campaign For London', 'Campaign For UK'], $returnCampaignNames);
    }
}
