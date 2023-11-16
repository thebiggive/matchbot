<?php

namespace MatchBot\Client;


use Stripe\PaymentIntent;

/**
 * Abstraction for talking to stripe, either with a real HTTP connection or an imaginary version of stripe for use in
 * load tests or any other tests. This interface should generally be used in all other code in preference to
 * directly relying on \Stripe\StripeClient.
 *
 * As well as allowing load tests this should also make writing unit tests a lot easier, as the functions in this
 * interface should suit what we can easily mock using e.g. Prophecy much better than the API of Stripe's library.
 */
interface Stripe
{
    public function cancelPaymentIntent(string $paymentIntentId): void;

    public function updatePaymentIntent(string $paymentIntentId, array $updateData): void;

    public function confirmPaymentIntent(string $paymentIntentId): PaymentIntent;

    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntent;
}