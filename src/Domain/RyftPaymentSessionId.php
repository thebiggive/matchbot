<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;

readonly class RyftPaymentSessionId
{
    private function __construct(public string $id)
    {
        Assertion::regex(
            $this->id,
            '/^ps_[0-7][0-9A-HJKMNP-TV-Z]{25}/',
            'Value "%s" does not match regex for ryft payment session'
        );
    }

    public static function of(string $id): self
    {
        return new self($id);
    }
}
