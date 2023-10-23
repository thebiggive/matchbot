<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assert;
use MatchBot\Application\LazyAssertionException;

/**
 * @psalm-immutable
 * @Embeddable
 */
class DonorName
{
    /** @Column(type = "string") */
    public string $first;

    /** @Column(type = "string") */
    private string $last;

    /**
     * @psalm-suppress ImpureMethodCall - \Assert\Assert::lazy etc could probably be marked as pure but is not.
     * @throws LazyAssertionException
     */
    private function __construct(string $first, string $last)
    {
        Assert::lazy()
            ->that($first, 'first')->betweenLength(1, 255)
            ->that($last, 'last')->betweenLength(1, 255)
            ->verifyNow();

        $this->first = $first;
        $this->last = $last;
    }

    /**
     * @throws LazyAssertionException
     */
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