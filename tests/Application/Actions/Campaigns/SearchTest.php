<?php

namespace MatchBot\Tests\Application\Actions\Campaigns;

use GuzzleHttp\Psr7\ServerRequest;
use Laminas\Diactoros\Response;
use MatchBot\Application\Actions\Campaigns\Search;
use MatchBot\Client\FindThatPostcode;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignSearchResult;
use MatchBot\Domain\CampaignService;
use MatchBot\Domain\Money;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;
use Psr\Log\NullLogger;

class SearchTest extends TestCase
{
    public function testSearchReturnsCountriesAndThemesInResponse(): void
    {
        // arrange
        $campaignRepositoryProphecy = $this->prophesize(CampaignRepository::class);
        $campaignServiceProphecy = $this->prophesize(CampaignService::class);
        $findThatPostcodeProphecy = $this->prophesize(FindThatPostcode::class);

        $campaign = $this->createCampaign(
            sfId: '111111111111111111',
            locations: [
                ['countryName' => 'United Kingdom', 'regionCode' => null],
                ['countryName' => 'Yemen', 'regionCode' => null],
            ],
            beneficiaries: ['Minority ethnic'],
            categories: ['Children'],
        );

        $searchResult = new CampaignSearchResult(
            campaigns: [$campaign],
            locationCounts: [],
            ukFilterRegions: null,
            childRegions: [],
            siblingRegions: [],
            parentRegion: null,
            parentRegionName: null,
            countries: [
                ['country' => 'United Kingdom', 'numCampaigns' => 1],
                ['country' => 'Yemen', 'numCampaigns' => 1],
            ],
            themes: [
                ['theme' => 'Children', 'numCampaigns' => 1],
                ['theme' => 'Minority ethnic', 'numCampaigns' => 1],
            ],
        );

        $campaignRepositoryProphecy
            ->search(
                'distanceToTarget',
                'desc',
                0,
                20,
                null,
                null,
                [],
                null,
                null,
                null,
                false,
                false,
            )
            ->willReturn($searchResult);

        $campaignServiceProphecy
            ->renderCampaignSummary($campaign)
            ->willReturn([
                'id' => '111111111111111111',
                'title' => 'Test Campaign 111111111111111111',
            ]);

        $sut = new Search(
            new NullLogger(),
            $campaignRepositoryProphecy->reveal(),
            $campaignServiceProphecy->reveal(),
            $findThatPostcodeProphecy->reveal(),
        );

        // act
        $request = new ServerRequest('GET', '/campaigns');
        $response = $sut($request, new Response(), []);

        // assert
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($body);

        $this->assertArrayHasKey('countries', $body);
        $this->assertArrayHasKey('themes', $body);
        $this->assertArrayHasKey('campaignSummaries', $body);

        $this->assertSame(
            [
                ['country' => 'United Kingdom', 'numCampaigns' => 1],
                ['country' => 'Yemen', 'numCampaigns' => 1],
            ],
            $body['countries'],
        );

        $this->assertSame(
            [
                ['theme' => 'Children', 'numCampaigns' => 1],
                ['theme' => 'Minority ethnic', 'numCampaigns' => 1],
            ],
            $body['themes'],
        );
    }

    /**
     * @param list<array{countryName: ?string, regionCode: ?string}> $locations
     * @param list<string> $beneficiaries
     * @param list<string> $categories
     */
    private function createCampaign(
        string $sfId,
        array $locations,
        array $beneficiaries,
        array $categories,
    ): Campaign {
        $rawData = [
            'id' => $sfId,
            'isMetaCampaign' => false,
            'isPublished' => true,
            'title' => 'Test Campaign ' . $sfId,
            'locations' => $locations,
            'beneficiaries' => $beneficiaries,
            'categories' => $categories,
            'championName' => '',
            'bannerUri' => null,
            'currencyCode' => 'GBP',
            'amountRaised' => 0.0,
            'startDate' => '2025-01-01T00:00:00.000Z',
            'endDate' => '2030-12-31T00:00:00.000Z',
            'isMatched' => true,
            'isRegularGiving' => false,
            'matchFundsTotal' => 0.0,
            'matchFundsRemaining' => 0.0,
            'charity' => [
                'id' => 'charity12345678901',
                'name' => 'Charity Name',
                'optInStatement' => '',
                'regulatorNumber' => '123456',
                'regulatorRegion' => 'England and Wales',
                'stripeAccountId' => 'acc_123',
            ],
        ];

        return new Campaign(
            sfId: Salesforce18Id::ofCampaign($sfId),
            metaCampaignSlug: null,
            charity: TestCase::someCharity(),
            startDate: new \DateTimeImmutable('2025-01-01'),
            endDate: new \DateTimeImmutable('2030-12-31'),
            isMatched: true,
            isPublished: true,
            name: 'Test Campaign ' . $sfId,
            summary: 'Summary',
            currencyCode: 'GBP',
            isRegularGiving: false,
            pinPosition: null,
            championPagePinPosition: null,
            relatedApplicationStatus: null,
            relatedApplicationCharityResponseToOffer: null,
            regularGivingCollectionEnd: null,
            totalFundraisingTarget: Money::zero(),
            thankYouMessage: null,
            locations: $locations,
            rawData: $rawData,
            hidden: false,
        );
    }
}
