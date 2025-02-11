<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Embeddable;

readonly class PostCode
{
    private function __construct(public string $value, bool $_homeIsOutsideUK)
    {
    }

    public static function of(string $value, bool $skipUKSpecificValidation = false): self
    {
        return new self($value, $skipUKSpecificValidation);
    }
}
