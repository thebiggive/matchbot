<?php

namespace MatchBot\Client;

use MatchBot\Domain\Currency;
use MatchBot\Domain\Money;
use MatchBot\Domain\StripeCustomerId;
use Stripe\StripeClient;

class Stripe
{
    public function __construct(private StripeClient $stripeClient)
    {
    }

    public function fetchBalance(StripeCustomerId $stripeCustomerId, Currency $currency): Money
    {
        $stripeCustomer = $this->stripeClient->customers->retrieve($stripeCustomerId->stripeCustomerId, [
            'expand' => ['cash_balance'],
        ]);

        $balanceIsApplicable = (
            property_exists($stripeCustomer, 'cash_balance') && $stripeCustomer->cash_balance &&
            $stripeCustomer->cash_balance->available !== null &&
            $stripeCustomer->cash_balance->settings->reconciliation_mode === 'automatic'
        );

        if ($balanceIsApplicable) {
            /** @var array<string, int> $allBalances */
            $allBalances = $stripeCustomer->cash_balance->available->toArray();
            foreach ($allBalances as $currencyCode => $balance) {
                if (strtoupper($currencyCode) === $currency->isoCode()) {
                    return Money::fromPence($balance, $currency);
                }
            }
        }

        return Money::fromPence(0, $currency);
    }
}
