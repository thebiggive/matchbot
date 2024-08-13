<?php

namespace MatchBot\Domain;

use JsonSerializable;
use MatchBot\Application\Assertion;
use MatchBot\Application\AssertionFailedException;

/**
 * @psalm-template-covariant T of SalesforceProxy
 */
class Salesforce18Id implements JsonSerializable
{
    /**
     * @param string $value
     * @psalm-param class-string<T> $_entityClass
     */
    private function __construct(public readonly string $value, ?string $_entityClass)
    {
        Assertion::length($value, 18);
        Assertion::regex($value, '/[a-zA-Z0-9]{18}/');
    }

    /**
     * @throws AssertionFailedException
     */
    public static function of(string $value): self
    {
        // I think we could also validate a checksum here but as the IDs are generally not hand typed that won't get us
        // much.

        return new self($value, null);
    }

    /**
     * @return self<Charity>
     */
    public static function ofCharity(string $id): self
    {
        return new self($id, Charity::class);
    }

    /**
     * @return self<Campaign>
     */
    public static function ofCampaign(string $id): self
    {
        return new self($id, Campaign::class);
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod - used indirectly
     */
    public function jsonSerialize(): string
    {
        return $this->value;
    }
}
