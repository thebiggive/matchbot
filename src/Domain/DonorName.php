<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assert;
use MatchBot\Application\Assertion;
use MatchBot\Application\LazyAssertionException;

/**
 * @psalm-immutable
 */
#[Embeddable]
class DonorName
{
    #[Column(type: 'string')]
    public string $first;

    #[Column(type: 'string')]
    public string $last;

    /**
     * @psalm-suppress ImpureMethodCall - \Assert\Assert::lazy etc could probably be marked as pure but is not.
     * @throws LazyAssertionException
     */
    private function __construct(string $first, string $last)
    {
        // long numbers are almost certainly mistakes, could be sensitive e.g. payment card no.
        // Even if spaces between digits.
        $sixDigitsRegex = '/\d\s?\d\s?\d\s?\d\s?\d\s?\d/';

        // first name may be empty to account for organisation donors who only have a last name
        Assert::lazy()
            ->that($first, 'first')->betweenLength(0, 255)
            ->that($last, 'last')->betweenLength(1, 255)
            ->that($first)->notRegex($sixDigitsRegex)
            ->that($last)->notRegex($sixDigitsRegex)
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
     * @param string|null $firstName
     * @param string|null $lastName
     * @return DonorName|null
     * @throws \Assert\AssertionFailedException
     */
    public static function maybeFromFirstAndLast(?string $firstName, ?string $lastName): ?self
    {
        $hasFirstName = !is_null($firstName) && $firstName !== '' && $firstName !== 'N/A';
        $hasLastName = !is_null($lastName) && $lastName !== '' && $lastName !== 'N/A';
        Assertion::same(
            $hasFirstName,
            $hasLastName,
            "First and last names must be supplied together or not at all."
        );

        return ($hasFirstName && $hasLastName) ? DonorName::of($firstName, $lastName) : null;
    }

    public function fullName(): string
    {
        if ($this->first === '') {
            return $this->last;
        }

        return "{$this->first} {$this->last}";
    }
}
