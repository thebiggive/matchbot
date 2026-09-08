<?php

namespace MatchBot\Domain;

use Psr\Http\Message\UriInterface;

/**
 * Class is not yet in use.
 * For now to be used in MetaCampaigns, later maybe also for Charity Campaigns.
 *
 * @psalm-suppress PossiblyUnusedMethod
 * @psalm-suppress PossiblyUnusedProperty
 * @psalm-api
 */
readonly class Banner implements \JsonSerializable
{
    public function __construct(
        public UriInterface $uri,
        public ?string $altText,
    ) {
    }

    /**
     * @return array{uri: string, altText: ?string}
     */
    #[\Override]
    public function jsonSerialize(): array
    {
        return [
            'uri' => $this->uri->__toString(),
            'altText' => $this->altText,
        ];
    }
}
