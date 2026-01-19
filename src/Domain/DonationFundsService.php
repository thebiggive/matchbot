<?php

namespace MatchBot\Domain;

use MatchBot\Client\Stripe;

class DonationFundsService
{
    /**
     * @psalm-suppress PossiblyUnusedMethod - used by DI container
     */
    public function __construct(
        private readonly Stripe $stripe
    ) {
    }

    public function refundFullBalanceToCustomer(DonorAccount $donorAccount): void
    {
        $stripeCustomer = $this->stripe->retrieveCustomer($donorAccount->stripeCustomerId, ['expand' => ['cash_balance']]);
        if ($stripeCustomer->cash_balance === null || $stripeCustomer->cash_balance->available === null) {
            return;
        }

        /**
         * @var string $currencyCode
         * @var int $amount
         */
        foreach ($stripeCustomer->cash_balance->available->toArray() as $currencyCode => $amount) {
            if ($amount === 0) {
                continue;
            }

            $money = Money::fromPence($amount, Currency::fromIsoCode($currencyCode));
            $this->stripe->refundCustomerBalance($donorAccount->stripeCustomerId, $money);
        }
    }
}
