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
     * @param array<string, int> $locationCounts Count of how many campaigns have impact in
     * each given region within the UK, for map display.
     */
    public function __construct(public array $campaigns, public array $locationCounts = [])
    {
    }
}
