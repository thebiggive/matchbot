<?php

namespace MatchBot\Client;

use MatchBot\Domain\Donation;
use MatchBot\Domain\StripeConfirmationTokenId;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Domain\StripePaymentMethodId;
use Stripe\ConfirmationToken;
use Stripe\CustomerSession;
use Stripe\Exception\InvalidArgumentException;
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

    public function retrieveConfirmationToken(StripeConfirmationTokenId $confirmationTokenId): ConfirmationToken
    {
        return $this->stripeClient->confirmationTokens->retrieve($confirmationTokenId->stripeConfirmationTokenId);
    }

    public function createPaymentIntent(array $createPayload): PaymentIntent
    {
        return $this->stripeClient->paymentIntents->create($createPayload);
    }

    public function createCustomerSession(StripeCustomerId $stripeCustomerId): CustomerSession
    {
        return $this->stripeClient->customerSessions->create([
            'customer' => $stripeCustomerId->stripeCustomerId,
            'components' => [
                'payment_element' => [
                    'enabled' => true,
                    'features' => [
                        'payment_method_allow_redisplay_filters' => ['always', 'unspecified'],
                        'payment_method_redisplay' => 'enabled',
                        'payment_method_redisplay_limit' => 3, // Keep default 3; 10 is max stripe allows.
                        // default value – need to ensure it stays off to avoid breaking Regular Giving by mistake,
                        // since the list can include `off_session` saved cards that may be mandate-linked.
                        'payment_method_remove' => 'disabled',
                        'payment_method_save' => 'enabled',

                        // off-session (Regular Giving) payment methods will be saved separately.
                        // @todo-regular-giving link to that when implemented.
                        'payment_method_save_usage' => 'on_session',
                    ],
                ]
            ],
        ]);
    }
}
