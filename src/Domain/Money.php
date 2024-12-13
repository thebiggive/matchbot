<?php

namespace MatchBot\Domain;

use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Embeddable;
use MatchBot\Application\Assertion;
use Doctrine\ORM\Mapping as ORM;

/**
 * @psalm-immutable
 */
#[Embeddable]
readonly class Money implements \JsonSerializable, \Stringable
{
    /**
     * @param int $amountInPence - Amount of money in minor units, i.e. pence, assumed to be worth 1/100 of the major
     * unit. Has upper limit set above what we expect to ever deal with on a single account.
     * @param Currency $currency
     */
    private function __construct(
        #[Column(type: 'integer')]
        public int $amountInPence,
        #[Column(type: 'string', enumType: Currency::class)]
        public Currency $currency
    ) {
        Assertion::between(
            $this->amountInPence,
            0,
            20_000_000_00 // this is nearly PHP_INT_MAX on 32 bit systems.
        );
    }

    public static function fromPence(int $amountInPence, Currency $currency): self
    {
        return new self($amountInPence, $currency);
    }

    public static function fromPoundsGBP(int $pounds): self
    {
        return new self($pounds * 100, Currency::GBP);
    }

    public static function sum(self ...$amounts): self
    {
        return array_reduce(
            $amounts,
            static fn (self $a, self $b): self => $a->plus($b),
            self::zero(),
        );
    }

    private static function zero(): self
    {
        return new self(0, Currency::GBP);
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

    /**
     * @psalm-suppress PossiblyUnusedMethod - used indirectly
     */
    public function jsonSerialize(): mixed
    {
        return ['amountInPence' => $this->amountInPence, 'currency' => $this->currency->isoCode()];
    }

    public function lessThan(Money $that): bool
    {
        if ($this->currency !== $that->currency) {
            throw new \UnexpectedValueException("Cannot compare amounts with different currencies");
        }

        return $this->amountInPence < $that->amountInPence;
    }

    public function moreThan(Money $that): bool
    {
        if ($this->currency !== $that->currency) {
            throw new \UnexpectedValueException("Cannot compare amounts with different currencies");
        }

        return $this->amountInPence > $that->amountInPence;
    }

    public function __toString()
    {
        return $this->currency->isoCode() . ' ' . ($this->amountInPence / 100);
    }

    /**
     * Returns an amount in major units as a string, e.g. '1.00' for one pound.
     * @return numeric-string
     */
    public function toNumericString(): string
    {
        return bcdiv((string) $this->amountInPence, '100', 2);
    }

    /**
     * @param numeric-string $amount
     */
    public static function fromNumericStringGBP(string $amount): self
    {
        $amountInPence = $amount * 100;
        /** @psalm-suppress ImpureMethodCall */
        Assertion::integerish($amountInPence);

        return new self((int) $amountInPence, Currency::GBP);
    }

    public function withPence(int $amountInPence): self
    {
        return new self($amountInPence, $this->currency);
    }

    public function plus(self $that): self
    {
        /** @psalm-suppress ImpureMethodCall */
        Assertion::same($this->currency, $that->currency);

        return new self($this->amountInPence +  $that->amountInPence, $this->currency);
    }
}
