<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * UUID of a person as given by our Identity service
 */
#[Embeddable]
readonly class PersonId
{
    #[Column(type: 'uuid')]
    public readonly UuidInterface $id;

    private function __construct(string $personId)
    {
        $this->id = Uuid::fromString($personId);
    }

    public static function of(string $personId): self
    {
        return new self($personId);
    }

    public static function ofUUID(UuidInterface $personId): self
    {
        return new self($personId->toString());
    }

    public function equals(self $that): bool
    {
        return $this->id->equals($that->id);
    }

    public function toUUID(): UuidInterface
    {
        return $this->id;
    }

    public static function nil(): self
    {
        return self::of(Uuid::NIL);
    }
}
