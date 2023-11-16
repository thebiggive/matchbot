<?php

namespace MatchBot\Client;

use Stripe\PaymentIntent;

/**
 * Does not connect to stripe. For use in load testing to allow testing Matchbot with high traffic levels
 * without sending all that traffic to Stripe. Test mode stripe does not allow sending high volumes of test traffic
 * so we have to stub it out.
 */
class StubStripeClient implements Stripe
{

    public function cancelPaymentIntent(string $paymentIntentId): void
    {
        $this->pause();
    }

    /** Pause to simulate waiting for an HTTP response from Stripe */
    public function pause(): void
    {
        // half second
        usleep(500_000);
    }

    public function updatePaymentIntent(string $paymentIntentId, array $updateData): void
    {
        $this->pause();
    }

    public function confirmPaymentIntent(string $paymentIntentId): Never
    {
        throw new \Exception("Confirm Payment Intent not implemented in stub - not currently used in load tests");
    }

    public function retrievePaymentIntent(string $paymentIntentId): Never
    {
        throw new \Exception("Retrieve Payment Intent not implemented in stub- not currently used in load tests");
    }
}