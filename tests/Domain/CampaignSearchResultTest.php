<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignSearchResult;
use MatchBot\Domain\Money;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;

class CampaignSearchResultTest extends TestCase
{
    public function testDerivesCountriesAndThemesCorrectly(): void
    {
        // Campaign 1: UK (multiple regional locations) and Yemen, themes: Minority ethnic, Children, Education
        $campaign1 = $this->createCampaignWithLocationsAndThemes(
            sfId: '111111111111111111',
            locations: [
                ['countryName' => 'United Kingdom', 'regionCode' => 'E12000007'],
                ['countryName' => 'United Kingdom', 'regionCode' => 'E09000014'],
                ['countryName' => 'Yemen', 'regionCode' => null],
            ],
            beneficiaries: ['Minority ethnic', 'Children'],
            categories: ['Education/Training/Employment'],
        );

        // Campaign 2: UK, Antarctica; themes: Minority ethnic, Environment
        $campaign2 = $this->createCampaignWithLocationsAndThemes(
            sfId: '222222222222222222',
            locations: [
                ['countryName' => 'United Kingdom', 'regionCode' => null],
                ['countryName' => 'Antarctica', 'regionCode' => null],
            ],
            beneficiaries: ['Minority ethnic'],
            categories: ['Environment/Conservation'],
        );

        // Campaign 3: British Virgin Islands, Yemen; themes: Children, Health
        $campaign3 = $this->createCampaignWithLocationsAndThemes(
            sfId: '333333333333333333',
            locations: [
                ['countryName' => 'British Virgin Islands', 'regionCode' => null],
                ['countryName' => 'Yemen', 'regionCode' => null],
            ],
            beneficiaries: ['Children'],
            categories: ['Health'],
        );

        // Campaign 4: No country location (only UK region code without countryName), theme: Health (duplicate in both arrays)
        $campaign4 = $this->createCampaignWithLocationsAndThemes(
            sfId: '444444444444444444',
            locations: [
                ['countryName' => null, 'regionCode' => 'E12000007'],
            ],
            beneficiaries: ['Health'],
            categories: ['Health'],
        );

        $searchResult = new CampaignSearchResult(
            campaigns: [$campaign1, $campaign2, $campaign3, $campaign4],
            locationCounts: [],
            ukFilterRegions: null,
            childRegions: [],
            siblingRegions: [],
            parentRegion: null,
            parentRegionName: null,
        );

        // Expected countries:
        // United Kingdom: 2 (campaigns 1, 2)
        // Yemen: 2 (campaigns 1, 3)
        // Antarctica: 1 (campaign 2)
        // British Virgin Islands: 1 (campaign 3)
        // Note: sorted by count DESC, then name ASC.
        $this->assertSame(
            [
                ['country' => 'United Kingdom', 'numCampaigns' => 2],
                ['country' => 'Yemen', 'numCampaigns' => 2],
                ['country' => 'Antarctica', 'numCampaigns' => 1],
                ['country' => 'British Virgin Islands', 'numCampaigns' => 1],
            ],
            $searchResult->countries,
        );

        // Expected themes:
        // Children: 2 (campaigns 1, 3)
        // Health: 2 (campaigns 3, 4)
        // Minority ethnic: 2 (campaigns 1, 2)
        // Education/Training/Employment: 1 (campaign 1)
        // Environment/Conservation: 1 (campaign 2)
        // Note: sorted by count DESC, then name ASC.
        $this->assertSame(
            [
                ['theme' => 'Children', 'numCampaigns' => 2],
                ['theme' => 'Health', 'numCampaigns' => 2],
                ['theme' => 'Minority ethnic', 'numCampaigns' => 2],
                ['theme' => 'Education/Training/Employment', 'numCampaigns' => 1],
                ['theme' => 'Environment/Conservation', 'numCampaigns' => 1],
            ],
            $searchResult->themes,
        );
    }

    public function testHandlesEmptyCampaigns(): void
    {
        $searchResult = new CampaignSearchResult(
            campaigns: [],
            locationCounts: [],
            ukFilterRegions: null,
            childRegions: [],
            siblingRegions: [],
            parentRegion: null,
            parentRegionName: null,
        );

        $this->assertSame([], $searchResult->countries);
        $this->assertSame([], $searchResult->themes);
    }

    public function testAllowsExplicitCountriesAndThemes(): void
    {
        $customCountries = [['country' => 'Custom Land', 'numCampaigns' => 5]];
        $customThemes = [['theme' => 'Custom Theme', 'numCampaigns' => 10]];

        $searchResult = new CampaignSearchResult(
            campaigns: [],
            locationCounts: [],
            ukFilterRegions: null,
            childRegions: [],
            siblingRegions: [],
            parentRegion: null,
            parentRegionName: null,
            countries: $customCountries,
            themes: $customThemes,
        );

        $this->assertSame($customCountries, $searchResult->countries);
        $this->assertSame($customThemes, $searchResult->themes);
    }

    /**
     * @param list<array{countryName: ?string, regionCode: ?string}> $locations
     * @param list<string> $beneficiaries
     * @param list<string> $categories
     */
    private function createCampaignWithLocationsAndThemes(
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
