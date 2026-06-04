<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;

readonly class RyftPaymentSessionId
{
    private function __construct(
        public string $id,
    ) {
        Assertion::regex(
            $this->id,
            '/^ps_[0-7][0-9A-HJKMNP-TV-Z]{25}/',
            'Value "%s" does not match regex for ryft payment session',
        );
    }

    /**
     * @param string $id - for instance 'ps_01FCTS1XMKH9FF43CAFA4CXT3P'
     * @return self
     */
    public static function of(string $id): self
    {
        return new self($id);
    }
}
