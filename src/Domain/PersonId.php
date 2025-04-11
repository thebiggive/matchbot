<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assertion;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

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

    public function toUUID(): UuidInterface
    {
        return Uuid::fromString($this->id);
    }

    public static function nil(): self
    {
        return self::of(Uuid::NIL);
    }
}
