<?php

namespace MatchBot\Domain;

use Assert\AssertionFailedException;
use MatchBot\Application\Assertion;

readonly class MetaCampaignSlug
{
    /**
     * @throws AssertionFailedException
     */
    private function __construct(
        public string $slug,
    ) {
        Assertion::betweenLength($slug, minLength: 2, maxLength: 100);
        Assertion::regex($slug, '/^[A-Za-z0-9-]+$/');
    }

    /**
     * @throws AssertionFailedException
     */
    public static function of(string $slug): self
    {
        return new self($slug);
    }
}
