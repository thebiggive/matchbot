<?php

namespace MatchBot\Client;

use MatchBot\Domain\StripeConfirmationTokenId;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Domain\StripePaymentMethodId;
use Stripe\BalanceTransaction;
use Stripe\Charge;
use Stripe\ConfirmationToken;
use Stripe\CustomerSession;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\SetupIntent;
use Stripe\StripeClient;

/**
 * Connects to a real Stripe service, either in test mode or production mode.
 *
 */
class LiveStripeClient implements Stripe
{
    public const array SESSION_COMPONENTS = [
        'payment_element' => [
            'enabled' => true,
            'features' => [
                'payment_method_redisplay' => 'enabled',

                // include both recently added cards where donor ticked box to have card saved, and older cards
                // where we didn't ask them.
                'payment_method_allow_redisplay_filters' => ['always', 'unspecified'],

                'payment_method_redisplay_limit' => 3, // Keep default 3; 10 is max stripe allows.
                // default value – need to ensure it stays off to avoid breaking Regular Giving by mistake,
                // since the list can include `off_session` saved cards that may be mandate-linked.
                'payment_method_remove' => 'disabled',
                'payment_method_save' => 'enabled',
                'payment_method_save_usage' => 'on_session',
            ],
        ]
    ];

    public function __construct(private StripeClient $stripeClient)
    {
    }

    #[\Override]
    public function cancelPaymentIntent(string $paymentIntentId): void
    {
        $this->stripeClient->paymentIntents->cancel($paymentIntentId);
    }

    /**
     * @psalm-suppress InvalidArgument (see comment inside function)
     */
    #[\Override]
    public function updatePaymentIntent(string $paymentIntentId, array $updateData): void
    {
        // see https://github.com/stripe/stripe-php/issues/1854 "The doctype regarding metadata is wrong"
        // @phpstan-ignore argument.type
        $this->stripeClient->paymentIntents->update($paymentIntentId, $updateData);
    }

    #[\Override]
    public function confirmPaymentIntent(string $paymentIntentId, array $params): PaymentIntent
    {
        return $this->stripeClient->paymentIntents->confirm($paymentIntentId, $params);
    }

    #[\Override]
    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntent
    {
        return $this->stripeClient->paymentIntents->retrieve($paymentIntentId);
    }

    #[\Override]
    public function retrieveConfirmationToken(StripeConfirmationTokenId $confirmationTokenId): ConfirmationToken
    {
        return $this->stripeClient->confirmationTokens->retrieve($confirmationTokenId->stripeConfirmationTokenId);
    }

    #[\Override]
    public function retrieveCharge(string $chargeId): Charge
    {
        return $this->stripeClient->charges->retrieve($chargeId);
    }

    #[\Override]
    public function createPaymentIntent(array $createPayload): PaymentIntent
    {
        return $this->stripeClient->paymentIntents->create($createPayload);
    }

    #[\Override]
    public function createSetupIntent(StripeCustomerId $stripeCustomerId): SetupIntent
    {
        return $this->stripeClient->setupIntents->create([
            'customer' => $stripeCustomerId->stripeCustomerId,
                'automatic_payment_methods' => ['enabled' => true],
            ]);
    }

    #[\Override]
    public function createCustomerSession(StripeCustomerId $stripeCustomerId): CustomerSession
    {
        return $this->stripeClient->customerSessions->create([
            'customer' => $stripeCustomerId->stripeCustomerId,
            'components' => self::SESSION_COMPONENTS,
        ]);
    }

    #[\Override]
    public function createRegularGivingCustomerSession(StripeCustomerId $stripeCustomerId): CustomerSession
    {
        $components = self::SESSION_COMPONENTS;
        $components['payment_element']['features']['payment_method_save_usage'] = 'off_session';
        $components['payment_element']['features']['payment_method_redisplay'] = 'disabled'; // Ensure method's not removed during non-RG checkout
        unset($components['payment_element']['features']['payment_method_allow_redisplay_filters']);
        unset($components['payment_element']['features']['payment_method_redisplay_limit']);

        return $this->stripeClient->customerSessions->create([
            'customer' => $stripeCustomerId->stripeCustomerId,
            'components' => $components,
        ]);
    }

    #[\Override]
    public function retrieveBalanceTransaction(string $id): BalanceTransaction
    {
        return $this->stripeClient->balanceTransactions->retrieve($id);
    }

    #[\Override]
    public function retrievePaymentMethod(StripeCustomerId $customerId, StripePaymentMethodId $methodId): PaymentMethod
    {
        return $this->stripeClient->customers->retrievePaymentMethod(
            $customerId->stripeCustomerId,
            $methodId->stripePaymentMethodId
        );
    }


    #[\Override]
    public function detatchPaymentMethod(StripePaymentMethodId $paymentMethodId): void
    {
        $this->stripeClient->paymentMethods->detach($paymentMethodId->stripePaymentMethodId);
    }
}
