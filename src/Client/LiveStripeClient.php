<?php

namespace MatchBot\Client;

use MatchBot\Domain\Donation;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\StripeClient;

/**
 * Connects to a real Stripe service, either in test mode or production mode.
 *
 */
class LiveStripeClient implements Stripe
{
    public function __construct(private StripeClient $stripeClient)
    {
    }

    public function cancelPaymentIntent(string $paymentIntentId): void
    {
        $this->stripeClient->paymentIntents->cancel($paymentIntentId);
    }

    public function updatePaymentIntent(string $paymentIntentId, array $updateData): void
    {
        $this->stripeClient->paymentIntents->update($paymentIntentId, $updateData);
    }

    public function confirmPaymentIntent(string $paymentIntentId, array $params = []): PaymentIntent
    {
        return $this->stripeClient->paymentIntents->confirm($paymentIntentId, $params);
    }

    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return $this->stripeClient->paymentIntents->retrieve($paymentIntentId);
    }

    public function createPaymentIntent(array $createPayload): PaymentIntent
    {
        return $this->stripeClient->paymentIntents->create($createPayload);
    }

    public function updatePaymentMethodBillingDetail(string $paymentMethodId, Donation $donation): PaymentMethod
    {
        // "A PaymentMethod must be attached a customer to be updated." In tests so far, Stripe seems to permit
        // repeated attachments to the same customer.
        $this->stripeClient->paymentMethods->attach($paymentMethodId, ['customer' => $donation->getPspCustomerId()]);

        // Address etc. is set up in Stripe.js already. Adding these values which we collect on the
        // donation separately helps with support queries and maybe with fraud signals.
        return $this->stripeClient->paymentMethods->update(
            $paymentMethodId,
            [
                'billing_details' => [
                    'name' => $donation->getDonorFullName(),
                    'email' => $donation->getDonorEmailAddress(),
                ],
            ],
        );
    }
}
