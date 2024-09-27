<?php

namespace MatchBot\Client;

use MatchBot\Domain\StripeConfirmationTokenId;
use MatchBot\Domain\StripeCustomerId;
use Ramsey\Uuid\Uuid;
use Stripe\ConfirmationToken;
use Stripe\CustomerSession;
use Stripe\PaymentIntent;
use Stripe\StripeObject;

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

    public function confirmPaymentIntent(string $paymentIntentId, array $params = []): PaymentIntent
    {
        $this->pause();

        $pi = new PaymentIntent($paymentIntentId);
        $pi->status = PaymentIntent::STATUS_SUCCEEDED;

        return $pi;
    }

    public function retrievePaymentIntent(string $paymentIntentId): never
    {
        throw new \Exception("Retrieve Payment Intent not implemented in stub- not currently used in load tests");
    }

    public function createPaymentIntent(array $createPayload): PaymentIntent
    {
        $this->pause();
        return new PaymentIntent('ST' . self::randomString());
    }

    private static function randomString(): string
    {
        return substr(Uuid::uuid4()->toString(), 0, 15);
    }

    public function createCustomerSession(StripeCustomerId $stripeCustomerId): CustomerSession
    {
        $session = new CustomerSession();
        $session->client_secret = 'fake_client_secret';

        return $session;
    }

    public function retrieveConfirmationToken(StripeConfirmationTokenId $confirmationTokenId): ConfirmationToken
    {
        $confirmationToken = new ConfirmationToken();
        $confirmationToken->payment_method_preview = new StripeObject();
        $confirmationToken->payment_method_preview['type'] = 'card';
        $confirmationToken->payment_method_preview['card'] = ['brand' => 'discover', 'country' => 'some-country'];

        return $confirmationToken;
    }
}
