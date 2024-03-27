<?php

namespace MatchBot\Domain;

use Assert\AssertionFailedException;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assertion;

#[Embeddable]
readonly class EmailAddress
{
    #[Column(type: 'string')]
    public readonly string $email;

    /**
     * @throws AssertionFailedException
     */
    private function __construct(
        string $value
    ) {
        $this->email = $value;
        Assertion::email($this->email);
    }

    /**
     * @param string $emailAddress - must be a valid email address.
     * @throws AssertionFailedException
     */
    public static function of(string $emailAddress): self
    {
        return new self($emailAddress);
    }
}
