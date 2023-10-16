<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assertion;

/**
 * @psalm-immutable
 * @Embeddable
 */
class EmailAddress
{
    /** @Column(type = "string") */
    public readonly string $email;

    private function __construct(
        string $value
    ){
        $this->email = $value;
        Assertion::email($this->email);
    }

    /**
     * @param string $emailAddress - must be a valid email address.
     */
    public static function of(string $emailAddress): self
    {
        return new self($emailAddress);
    }
}