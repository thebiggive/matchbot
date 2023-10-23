<?php

namespace MatchBot\Domain;

enum Currency
{
    case GBP;

    /**
     * E.g. '£', '$' or '€'
     */
    public function symbol(): string
    {
        return match ($this) {
            self::GBP => '£',
        };
    }
}
