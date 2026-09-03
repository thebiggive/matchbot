<?php

namespace MatchBot\Domain;

use Psr\Http\Message\UriInterface;

/**
 * Class is not yet in use.
 * For now to be used in MetaCampaigns, later maybe also for Charity Campaigns.
 *
 * @psalm-suppress PossiblyUnusedMethod
 * @psalm-suppress PossiblyUnusedProperty
 */

readonly class Banner
{
    public function __construct(
        public UriInterface $uri,
        public ?string $altText,
    ) {
    }
}
