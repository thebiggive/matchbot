<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;

/**
 * @psalm-immutable
 */
class Money
{
    /**
     * @param int $amountInPence - Amount of money in minor units, i.e. pence, assumed to be worth 1/100 of the major
     * unit. Has upper limit set above what we expect to ever deal with on a single account.
     * @param Currency $currency
     */
    private function __construct(
        private readonly int $amountInPence,
        private readonly Currency $currency
    ) {
        Assertion::between(
            $this->amountInPence,
            1,
            20_000_000_00 // this is nearly PHP_INT_MAX on 32 bit systems.
        );
    }

    public static function fromPence(int $amountInPence, Currency $currency): self
    {
        return new self($amountInPence, $currency);
    }

    /**
     * @return string Human-readable amount for use in English, e.g. "£17,000.00"
     */
    public function format(): string
    {
        /** @psalm-suppress ImpureMethodCall - not sure exactly why symbol is considered impure */
        return $this->currency->symbol() .
            number_format(
                num: $this->amountInPence / 100,
                decimals: 2,
                decimal_separator: '.',
                thousands_separator: ','
            );
    }
}
