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
     * unit. Must be between 1 and the maxiumum customer balance donation, currently 200_000_00 pence.
     * @param Currency $currency
     */
    private function __construct(
        private readonly int $amountInPence,
        private readonly Currency $currency
    ) {
        Assertion::between(
            $this->amountInPence,
            1,
            Donation::MAXIMUM_CUSTOMER_BALANCE_DONATION * 100
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
