<?php

namespace MatchBot\Domain;

use Psr\Http\Message\UriInterface;

/**
 * For now to be used in Metacampaigns, later maybe also for Charity Campaigns.
 */
readonly class Banner
{
    public function __construct(
        public UriInterface $uri,
        public ?string $altText,
    ) {
    }
}
