<?php

namespace MatchBot\Client;

use MatchBot\Domain\Donation;
use MatchBot\Domain\StripePaymentMethodId;
use Ramsey\Uuid\Uuid;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;

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

    /**
     * The actual billing data patch isn't important; the main job of the stub is to simulate
     * retrieving a Payment Method, since one is the return value of an update() in the Stripe SDK.
     */
    public function updatePaymentMethodBillingDetail(string $paymentMethodId, Donation $donation): void
    {
        $this->pause();
    }

    public function retrievePaymentMethod(StripePaymentMethodId $pmId): PaymentMethod
    {
        $this->pause();

        $paymentMethod = new PaymentMethod('ST' . self::randomString());
        $paymentMethod->type = 'card';
        /** @psalm-suppress PropertyTypeCoercion card */
        $paymentMethod->card = (object)['brand' => 'visa', 'country' => 'GB'];

        return $paymentMethod;
    }
}
