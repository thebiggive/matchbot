<?php

declare(strict_types=1);

namespace MatchBot\Domain;

enum PaymentMethodType: string
{
    case Card = 'card';
    case CustomerBalance = 'customer_balance';
    case PayByBank = 'pay_by_bank';

    public static function fromString(string $pspMethodType): self
    {
        return match ($pspMethodType) {
            'card' => self::Card,
            'apple_pay', 'google_pay' => self::Card, // from Matchbot's perspective apple and google payments work like card payments.
            'customer_balance' => self::CustomerBalance,
            'pay_by_bank' => self::PayByBank,
            default => throw new \InvalidArgumentException("Unknown payment method type: $pspMethodType"),
        };
    }

    public function usesPaymentElement(): bool
    {
        return match ($this) {
            self::Card, self::PayByBank => true,
            self::CustomerBalance => false,
        };
    }
}
