<?php

namespace MatchBot\Client;

use MatchBot\Domain\Donation;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Domain\StripePaymentMethodId;
use Stripe\CustomerSession;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;

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
    /**
     * @throws ApiErrorException
     */
    public function cancelPaymentIntent(string $paymentIntentId): void;

    /**
     * @throws ApiErrorException
     */
    public function updatePaymentIntent(string $paymentIntentId, array $updateData): void;

    /**
     * @throws ApiErrorException
     */
    public function confirmPaymentIntent(string $paymentIntentId, array $params = []): PaymentIntent;

    /**
     * @throws ApiErrorException
     */
    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntent;

    /**
     * @throws ApiErrorException
     * @throws InvalidRequestException - e.g. if the CVC wasn't collected, presumably due to bots accessing the system.
     */
    public function createPaymentIntent(array $createPayload): PaymentIntent;

    /**
     * @param non-empty-string $paymentMethodId
     * @throws ApiErrorException
     */
    public function updatePaymentMethodBillingDetail(string $paymentMethodId, Donation $donation): void;

    public function retrievePaymentMethod(StripePaymentMethodId $pmId): PaymentMethod;

    public function createCustomerSession(StripeCustomerId $stripeCustomerId): CustomerSession;
}
