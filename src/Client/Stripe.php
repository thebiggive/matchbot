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
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\SearchResult;
use Stripe\SetupIntent;

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
     * @param array{amount?: int, currency?: string, metadata?: array<string, mixed>, application_fee_amount?: int, payment_method?: null} $updateData
     */
    public function updatePaymentIntent(string $paymentIntentId, array $updateData): void;

    /**
     * @param array{confirmation_token?: string, off_session?: bool, payment_method?: string, return_url?: string} $params
     * @throws ApiErrorException
     */
    public function confirmPaymentIntent(string $paymentIntentId, array $params): PaymentIntent;

    /**
     * @throws ApiErrorException
     */
    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntent;

    /**
     * @param array{
     *     amount: int,
     *     currency: string
     * } $createPayload
     * @throws ApiErrorException
     * @throws InvalidRequestException - e.g. if the CVC wasn't collected, presumably due to bots accessing the system.
     */
    public function createPaymentIntent(array $createPayload): PaymentIntent;

    public function createCustomerSession(StripeCustomerId $stripeCustomerId): CustomerSession;

    /**
     * Creates a customer session that will save the given payment method for off-session use.
     */
    public function createRegularGivingCustomerSession(StripeCustomerId $stripeCustomerId): CustomerSession;

    public function retrieveConfirmationToken(StripeConfirmationTokenId $confirmationTokenId): ConfirmationToken;

    public function retrieveCharge(string $chargeId): Charge;

    public function retrieveBalanceTransaction(string $id): BalanceTransaction;

    public function createSetupIntent(StripeCustomerId $stripeCustomerId): SetupIntent;

    /**
     * @throws InvalidRequestException
     */
    public function retrievePaymentMethod(StripeCustomerId $customerId, StripePaymentMethodId $methodId): PaymentMethod;

    /**
     * @throws ApiErrorException
     */
    public function detatchPaymentMethod(StripePaymentMethodId $paymentMethodId): void;

    /**
     * Deletes a given customer from Stripe. (removing their credit card details and prevent operations, although
     * not erasing all data)
     *
     * See https://docs.stripe.com/api/customers/delete
     */
    public function deleteCustomer(StripeCustomerId $getStripeCustomerId): void;
}
