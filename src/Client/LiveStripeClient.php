<?php

namespace MatchBot\Client;

use MatchBot\Domain\Donation;
use MatchBot\Domain\StripeConfirmationTokenId;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Domain\StripePaymentMethodId;
use Stripe\BalanceTransaction;
use Stripe\Charge;
use Stripe\Collection;
use Stripe\ConfirmationToken;
use Stripe\CustomerSession;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidArgumentException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\SearchResult;
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
                'payment_method_allow_redisplay_filters' => ['always', 'unspecified'],
                'payment_method_redisplay' => 'enabled',
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

    public function retrieveCharge(string $chargeId): Charge
    {
        return $this->stripeClient->charges->retrieve($chargeId);
    }

    public function createPaymentIntent(array $createPayload): PaymentIntent
    {
        return $this->stripeClient->paymentIntents->create($createPayload);
    }

    public function createSetupIntent(StripeCustomerId $stripeCustomerId): SetupIntent
    {
        return $this->stripeClient->setupIntents->create([
            'customer' => $stripeCustomerId->stripeCustomerId,
                'automatic_payment_methods' => ['enabled' => true],
            ]);
    }

    public function createCustomerSession(StripeCustomerId $stripeCustomerId): CustomerSession
    {
        return $this->stripeClient->customerSessions->create([
            'customer' => $stripeCustomerId->stripeCustomerId,
            'components' => self::SESSION_COMPONENTS,
        ]);
    }

    public function createRegularGivingCustomerSession(StripeCustomerId $stripeCustomerId): CustomerSession
    {
        $components = self::SESSION_COMPONENTS;
        $components['payment_element']['features']['payment_method_save_usage'] = 'off_session';
        $components['payment_element']['features']['payment_method_redisplay'] = 'disabled';
        unset($components['payment_element']['features']['payment_method_allow_redisplay_filters']);
        unset($components['payment_element']['features']['payment_method_redisplay_limit']);

        return $this->stripeClient->customerSessions->create([
            'customer' => $stripeCustomerId->stripeCustomerId,
            'components' => $components,
        ]);
    }

    public function retrieveBalanceTransaction(string $id): BalanceTransaction
    {
        return $this->stripeClient->balanceTransactions->retrieve($id);
    }

    public function retrievePaymentMethod(StripeCustomerId $customerId, StripePaymentMethodId $methodId): PaymentMethod
    {
        return $this->stripeClient->customers->retrievePaymentMethod(
            $customerId->stripeCustomerId,
            $methodId->stripePaymentMethodId
        );
    }

    /**
     * @throws ApiErrorException
     */
    public function detatchPaymentMethod(StripePaymentMethodId $paymentMethodId): void
    {
        $this->stripeClient->paymentMethods->detach($paymentMethodId->stripePaymentMethodId);
    }
}
