<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assertion;

/**
 * UUID of a person as given by our Identity service
 * @psalm-immutable
 */
#[Embeddable]
class PersonId
{
    #[Column(type: 'uuid')]
    public readonly string $id;

    private function __construct(
        string $personId
    ) {
        $this->id = $personId;
        Assertion::uuid($personId);
    }

    public static function of(string $personId): self
    {
        return new self($personId);
    }

    public function equals(self $that): bool
    {
        return $this->id === $that->id;
    }
}
