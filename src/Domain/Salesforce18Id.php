<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;
use MatchBot\Application\AssertionFailedException;

class Salesforce18Id
{
    private function __construct(public readonly string $value)
    {
    }

    /**
     * @throws AssertionFailedException
     */
    public static function of(string $value): self
    {
        Assertion::length($value, 18);
        Assertion::regex($value, '/[a-zA-Z0-9]{18}/');

        // I think we could also validate a checksum here but as the IDs are generally not hand typed that won't get us
        // much.

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
