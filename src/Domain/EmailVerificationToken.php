<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\Column;

#[ORM\Entity(readOnly: true)]
class EmailVerificationToken
{
    /** @psalm-suppress UnusedProperty - ORM requires an ID */
    #[ORM\Id]
    #[ORM\Column(type: 'integer', unique: true)]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private ?int $id = null;

    /** @psalm-suppress PossiblyUnusedProperty - used in DQL query */
    #[Column()]
    public readonly \DateTimeImmutable $createdAt;

    /** @psalm-suppress PossiblyUnusedProperty - used in DQL query */
    #[Column()]
    public readonly string $emailAddress;
    #[Column()] public readonly string $randomCode;

    public function __construct(string $randomCode, string $emailAddress, \DateTimeImmutable $createdAt,)
    {
        $this->randomCode = $randomCode;
        $this->emailAddress = $emailAddress;
        $this->createdAt = $createdAt;
    }
}
