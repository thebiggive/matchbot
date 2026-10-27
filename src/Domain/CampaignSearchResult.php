<?php

namespace MatchBot\Domain;

/**
 * Currently just a wrapper around a list of campaigns, but intended to expand to include statistics on the result
 * (without considering the limit), that can be displayed alongside the list of campaigns. E.g. on the map of the UK.
 */
readonly class CampaignSearchResult
{
    /**
     * @var list<array{country: string, numCampaigns: int}>
     */
    public array $countries;

    /**
     * @var list<array{theme: string, numCampaigns: int}>
     */
    public array $themes;

    /**
     * @param list<Campaign> $campaigns List of campaigns in this page of search results.
     *
     * @param list<array{numCampaigns: int, regionCode: string}> $locationCounts Count of how many campaigns have impact in
     * each given region within the UK, for map display.
     *
     * @param list<string>|null $ukFilterRegions - if the search is filtered to a list of UK regions (e.g. because)
     * the request specified a lat/long point within these regions then this is a list of their ONS codes ordered from
     * smallest to largests o that the client can zoom to the largest one highlight one or more of them.
     *
     * @param list<string> $childRegions If filtered to UK regions then lists ONS codes of any relavent regions
     * inside the smallest filter region selected, that the donor may want to search next, e.g. all the other boroughs
     * + city of London as children if London is selected.
     *
     * @param list<string> $siblingRegions If filtered to UK regions then lists ONS codes other regions within the same
     * wider region as the most specific selected one, e.g. all the other boroughs + city of London as siblings if
     * Haringey is selected.
     *
     * @param string|null $parentRegion If applicable the ONS code of parent of the most specific region selected, e.g.
     * London as parent of Haringey. Should generally match the entry in position 1 of $ukFilterRegions. Null if the
     * regions only have the UK as a whole as a parent.
     *
     * @param list<array{country: string, numCampaigns: int}>|null $countries Count of how many campaigns represent each
     * country/location in the search results.
     *
     * @param list<array{theme: string, numCampaigns: int}>|null $themes Count of how many campaigns represent each
     * theme (beneficiaries + categories) in the search results.
     */
    public function __construct(
        public array $campaigns,
        public array $locationCounts,
        public ?array $ukFilterRegions,
        public array $childRegions,
        public array $siblingRegions,
        public ?string $parentRegion,
        public ?string $parentRegionName,
        ?array $countries = null,
        ?array $themes = null,
    ) {
        $this->countries = $countries ?? $this->deriveCountries($campaigns);
        $this->themes = $themes ?? $this->deriveThemes($campaigns);
    }

    /**
     * @param list<Campaign> $campaigns
     * @return list<array{country: string, numCampaigns: int}>
     */
    private function deriveCountries(array $campaigns): array
    {
        $counts = [];
        foreach ($campaigns as $campaign) {
            $campaignCountries = [];
            foreach ($campaign->getLocationsForApi() as $location) {
                $countryName = $location['countryName'];
                if (is_string($countryName) && trim($countryName) !== '') {
                    $campaignCountries[trim($countryName)] = true;
                }
            }
            foreach (array_keys($campaignCountries) as $country) {
                $counts[$country] = ( $counts[$country] ?? 0 ) + 1;
            }
        }

        $result = [];
        foreach ($counts as $country => $numCampaigns) {
            $result[] = [
                'country' => $country,
                'numCampaigns' => $numCampaigns,
            ];
        }

        usort($result, static function (array $a, array $b): int {
            if ($b['numCampaigns'] !== $a['numCampaigns']) {
                return $b['numCampaigns'] <=> $a['numCampaigns'];
            }
            return strcasecmp((string) $a['country'], (string) $b['country']);
        });

        return $result;
    }

    /**
     * @param list<Campaign> $campaigns
     * @return list<array{theme: string, numCampaigns: int}>
     */
    private function deriveThemes(array $campaigns): array
    {
        $counts = [];
        foreach ($campaigns as $campaign) {
            if ($campaign->isSfDataMissing()) {
                continue;
            }
            $sfData = $campaign->getSalesforceData();
            $beneficiaries = $sfData['beneficiaries'];
            $categories = $sfData['categories'];
            $campaignThemes = [];
            foreach ([...$beneficiaries, ...$categories] as $theme) {
                if (trim($theme) === '') {
                    continue;
                }

                if (\strtolower(trim($theme)) === 'other') {
                    continue;
                }

                $campaignThemes[trim($theme)] = true;
            }
            foreach (array_keys($campaignThemes) as $theme) {
                $counts[$theme] = ( $counts[$theme] ?? 0 ) + 1;
            }
        }

        $result = [];
        foreach ($counts as $theme => $numCampaigns) {
            $result[] = [
                'theme' => $theme,
                'numCampaigns' => $numCampaigns,
            ];
        }

        usort($result, static function (array $a, array $b): int {
            if ($b['numCampaigns'] !== $a['numCampaigns']) {
                return $b['numCampaigns'] <=> $a['numCampaigns'];
            }
            return strcasecmp((string) $a['theme'], (string) $b['theme']);
        });

        return $result;
    }
}
