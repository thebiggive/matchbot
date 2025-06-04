<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;

readonly class MetaCampaignSlug
{
    private function __construct(
        public string $slug,
    ) {
        Assertion::betweenLength($slug, minLength: 5, maxLength: 50);
        Assertion::regex($slug, '/^[A-Za-z0-9-]+$/');
    }

    public static function of(string $slug): self
    {
        return new self($slug);
    }
}
