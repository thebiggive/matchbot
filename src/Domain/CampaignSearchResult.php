<?php

namespace MatchBot\Domain;

/**
 * Currently just a wrapper around a list of campaigns, but intended to expand to include statistics on the result
 * (without considering the limit), that can be displayed alongside the list of campaigns. E.g. on the map of the UK.
 */
readonly class CampaignSearchResult
{
    /**
     * @param list<Campaign> $campaigns
     */
    public function __construct(public array $campaigns)
    {
    }
}
