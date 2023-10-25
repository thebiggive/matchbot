<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;

/**
 * @template T
 */
class Id
{
    /**
     * @param int $value
     * @param class-string<T> $identfiedClass
     * @throws \Assert\AssertionFailedException
     * @psalm-suppress PossiblyUnusedParam - $identfiedClass just exists to make type inference work.
     */
    public function __construct(public readonly int $value, string $identfiedClass)
    {
        Assertion::greaterOrEqualThan($this->value, 1);
    }
}
