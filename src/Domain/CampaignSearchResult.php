<?php

namespace MatchBot\Domain;

/**
 * Currently just a wrapper around a list of campaigns, but intended to expand to include statistics on the result
 * (without considering the limit), that can be displayed alongside the list of campaigns. E.g. on the map of the UK.
 */
readonly class CampaignSearchResult
{
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
     * ONS codes of other regions within the
     * same parent region as the smallest region (first in $ukFilterRegions),
     * e.g. if Harringey is selected will list all London Boroughs + City of London
     */
    public function __construct(
        public array $campaigns,
        public array $locationCounts,
        public array|null $ukFilterRegions,
        public array $childRegions,
        public array $siblingRegions,
        public ?string $parentRegion,
    ) {
    }
}
