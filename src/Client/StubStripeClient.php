<?php

namespace MatchBot\Client;

use MatchBot\Domain\StripeConfirmationTokenId;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Domain\StripePaymentMethodId;
use Ramsey\Uuid\Uuid;
use Stripe\BalanceTransaction;
use Stripe\Charge;
use Stripe\Collection;
use Stripe\ConfirmationToken;
use Stripe\CustomerSession;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\SearchResult;
use Stripe\SetupIntent;
use Stripe\StripeObject;

/**
 * Does not connect to stripe. For use in load testing to allow testing Matchbot with high traffic levels
 * without sending all that traffic to Stripe. Test mode stripe does not allow sending high volumes of test traffic
 * so we have to stub it out.
 */
class StubStripeClient implements Stripe
{
    #[\Override]
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

    #[\Override]
    public function updatePaymentIntent(string $paymentIntentId, array $updateData): void
    {
        $this->pause();
    }

    #[\Override]
    public function confirmPaymentIntent(string $paymentIntentId, array $params): PaymentIntent
    {
        $this->pause();

        $pi = new PaymentIntent($paymentIntentId);
        $pi->status = PaymentIntent::STATUS_SUCCEEDED;

        return $pi;
    }

    #[\Override]
    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntent
    {
        $this->pause();

        $pi = new PaymentIntent($paymentIntentId);
        $pi->setup_future_usage = null;

        return $pi;
    }

    #[\Override]
    public function createPaymentIntent(array $createPayload): PaymentIntent
    {
        $this->pause();
        return new PaymentIntent('pi_stub_' . self::randomString());
    }

    private static function randomString(): string
    {
        return substr(Uuid::uuid4()->toString(), 0, 15);
    }

    #[\Override]
    public function createCustomerSession(StripeCustomerId $stripeCustomerId): CustomerSession
    {
        $session = new CustomerSession();
        $session->client_secret = 'fake_client_secret';

        return $session;
    }

    #[\Override]
    public function retrieveConfirmationToken(StripeConfirmationTokenId $confirmationTokenId): ConfirmationToken
    {
        $confirmationToken = new ConfirmationToken();
        /** @psalm-suppress InvalidPropertyAssignmentValue */
        $confirmationToken->payment_method_preview = new StripeObject();

        /** @psalm-suppress InvalidPropertyAssignmentValue */
        $confirmationToken->payment_method_preview['type'] = 'card';

        /** @psalm-suppress InvalidPropertyAssignmentValue */
        $confirmationToken->payment_method_preview['card'] = ['brand' => 'discover', 'country' => 'GB'];

        /** @psalm-suppress InvalidPropertyAssignmentValue */
        $confirmationToken->payment_method_preview['pay_by_bank'] = null;

        return $confirmationToken;
    }

    #[\Override]
    public function createRegularGivingCustomerSession(StripeCustomerId $stripeCustomerId): CustomerSession
    {
        return $this->createCustomerSession($stripeCustomerId);
    }

    #[\Override]
    public function retrieveCharge(string $chargeId): Charge
    {
        throw new \Exception("Retrieve Charge not implemented in stub- not currently used in load tests");
    }

    #[\Override]
    public function retrieveBalanceTransaction(string $id): BalanceTransaction
    {
        throw new \Exception("Retrieve Balance Transaction not implemented in stub- not currently used in load tests");
    }

    #[\Override]
    public function createSetupIntent(StripeCustomerId $stripeCustomerId): SetupIntent
    {
        throw new \Exception("Create setup intent not implemented in stub - not currently used in load tests");
    }

    #[\Override]
    public function retrievePaymentMethod(StripeCustomerId $customerId, StripePaymentMethodId $methodId): PaymentMethod
    {
        throw new \Exception("Retrieve Payment Method not implemented in stub - not currently used in load tests");
    }

    #[\Override]
    public function detatchPaymentMethod(StripePaymentMethodId $paymentMethodId): void
    {
        throw new \Exception("Detatch Payment Method not implemented in stub - not currently used in load tests");
    }
}
