<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assert;

/**
 * @psalm-immutable
 * @Embeddable
 */
class DonorName
{
    /** @Column(type = "string") */
    private string $first;

    /** @Column(type = "string") */
    private string $last;

    /**
     * @param string $first
     * @param string $last
     * @psalm-suppress ImpureMethodCall - \Assert\Assert::lazy etc could probably be marked as pure but is not.
     */
    public function __construct(string $first, string $last)
    {
        Assert::lazy()
            ->that($first, 'first')->betweenLength(1, 255)
            ->that($last, 'last')->betweenLength(1, 255)
            ->verifyNow();

        $this->first = $first;
        $this->last = $last;
    }

    public static function of(string $first, string $last): self
    {
        return new self($first, $last);
    }

    /**
     * @psalm-suppress PossiblyUnusedMethod - likely to be used soon when we send emails.
     */
    public function fullName(): string
    {
        return "{$this->first} {$this->last}";
    }
}