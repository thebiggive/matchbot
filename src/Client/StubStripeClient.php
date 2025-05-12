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

    /**
     * @param array{amount: int, application_fee_amount?: int, automatic_payment_methods?: array{allow_redirects?: string, enabled: bool}, capture_method?: string, confirm?: bool, confirmation_method?: string, confirmation_token?: string, currency: string, customer?: string, description?: string, error_on_requires_action?: bool, expand?: string[], mandate?: string, mandate_data?: null|array{customer_acceptance: array{accepted_at?: int, offline?: array{}, online?: array{ip_address: string, user_agent: string}, type: string}}, metadata?: \Stripe\StripeObject, off_session?: array|bool|string, on_behalf_of?: string, payment_method?: string, payment_method_configuration?: string, payment_method_data?: array{acss_debit?: array{account_number: string, institution_number: string, transit_number: string}, affirm?: array{}, afterpay_clearpay?: array{}, alipay?: array{}, allow_redisplay?: string, alma?: array{}, amazon_pay?: array{}, au_becs_debit?: array{account_number: string, bsb_number: string}, bacs_debit?: array{account_number?: string, sort_code?: string}, bancontact?: array{}, billie?: array{}, billing_details?: array{address?: null|array{city?: string, country?: string, line1?: string, line2?: string, postal_code?: string, state?: string}, email?: null|string, name?: null|string, phone?: null|string, tax_id?: string}, blik?: array{}, boleto?: array{tax_id: string}, cashapp?: array{}, customer_balance?: array{}, eps?: array{bank?: string}, fpx?: array{account_holder_type?: string, bank: string}, giropay?: array{}, grabpay?: array{}, ideal?: array{bank?: string}, interac_present?: array{}, kakao_pay?: array{}, klarna?: array{dob?: array{day: int, month: int, year: int}}, konbini?: array{}, kr_card?: array{}, link?: array{}, metadata?: \Stripe\StripeObject, mobilepay?: array{}, multibanco?: array{}, naver_pay?: array{funding?: string}, nz_bank_account?: array{account_holder_name?: string, account_number: string, bank_code: string, branch_code: string, reference?: string, suffix: string}, oxxo?: array{}, p24?: array{bank?: string}, pay_by_bank?: array{}, payco?: array{}, paynow?: array{}, paypal?: array{}, pix?: array{}, promptpay?: array{}, radar_options?: array{session?: string}, revolut_pay?: array{}, samsung_pay?: array{}, satispay?: array{}, sepa_debit?: array{iban: string}, sofort?: array{country: string}, swish?: array{}, twint?: array{}, type: string, us_bank_account?: array{account_holder_type?: string, account_number?: string, account_type?: string, financial_connections_account?: string, routing_number?: string}, wechat_pay?: array{}, zip?: array{}}, payment_method_options?: array{acss_debit?: null|array{mandate_options?: array{custom_mandate_url?: null|string, interval_description?: string, payment_schedule?: string, transaction_type?: string}, setup_future_usage?: null|string, target_date?: string, verification_method?: string}, affirm?: null|array{capture_method?: null|string, preferred_locale?: string, setup_future_usage?: string}, afterpay_clearpay?: null|array{capture_method?: null|string, reference?: string, setup_future_usage?: string}, alipay?: null|array{setup_future_usage?: null|string}, alma?: null|array{capture_method?: null|string}, amazon_pay?: null|array{capture_method?: null|string, setup_future_usage?: null|string}, au_becs_debit?: null|array{setup_future_usage?: null|string, target_date?: string}, bacs_debit?: null|array{mandate_options?: array{reference_prefix?: null|string}, setup_future_usage?: null|string, target_date?: string}, bancontact?: null|array{preferred_language?: string, setup_future_usage?: null|string}, billie?: null|array{capture_method?: null|string}, blik?: null|array{code?: string, setup_future_usage?: null|string}, boleto?: null|array{expires_after_days?: int, setup_future_usage?: null|string}, card?: null|array{capture_method?: null|string, cvc_token?: string, installments?: array{enabled?: bool, plan?: null|array{count?: int, interval?: string, type: string}}, mandate_options?: array{amount: int, amount_type: string, description?: string, end_date?: int, interval: string, interval_count?: int, reference: string, start_date: int, supported_types?: string[]}, moto?: bool, network?: string, request_extended_authorization?: string, request_incremental_authorization?: string, request_multicapture?: string, request_overcapture?: string, request_three_d_secure?: string, require_cvc_recollection?: bool, setup_future_usage?: null|string, statement_descriptor_suffix_kana?: null|string, statement_descriptor_suffix_kanji?: null|string, three_d_secure?: array{ares_trans_status?: string, cryptogram: string, electronic_commerce_indicator?: string, exemption_indicator?: string, network_options?: array{cartes_bancaires?: array{cb_avalgo: string, cb_exemption?: string, cb_score?: int}}, requestor_challenge_indicator?: string, transaction_id: string, version: string}}, card_present?: null|array{request_extended_authorization?: bool, request_incremental_authorization_support?: bool, routing?: array{requested_priority?: string}}, cashapp?: null|array{capture_method?: null|string, setup_future_usage?: null|string}, customer_balance?: null|array{bank_transfer?: array{eu_bank_transfer?: array{country: string}, requested_address_types?: string[], type: string}, funding_type?: string, setup_future_usage?: string}, eps?: null|array{setup_future_usage?: string}, fpx?: null|array{setup_future_usage?: string}, giropay?: null|array{setup_future_usage?: string}, grabpay?: null|array{setup_future_usage?: string}, ideal?: null|array{setup_future_usage?: null|string}, interac_present?: null|array{}, kakao_pay?: null|array{capture_method?: null|string, setup_future_usage?: null|string}, klarna?: null|array{capture_method?: null|string, preferred_locale?: string, setup_future_usage?: string}, konbini?: null|array{confirmation_number?: null|string, expires_after_days?: null|int, expires_at?: null|int, product_description?: null|string, setup_future_usage?: string}, kr_card?: null|array{capture_method?: null|string, setup_future_usage?: null|string}, link?: null|array{capture_method?: null|string, persistent_token?: string, setup_future_usage?: null|string}, mobilepay?: null|array{capture_method?: null|string, setup_future_usage?: string}, multibanco?: null|array{setup_future_usage?: string}, naver_pay?: null|array{capture_method?: null|string, setup_future_usage?: null|string}, nz_bank_account?: null|array{setup_future_usage?: null|string, target_date?: string}, oxxo?: null|array{expires_after_days?: int, setup_future_usage?: string}, p24?: null|array{setup_future_usage?: string, tos_shown_and_accepted?: bool}, pay_by_bank?: null|array{}, payco?: null|array{capture_method?: null|string}, paynow?: null|array{setup_future_usage?: string}, paypal?: null|array{capture_method?: null|string, preferred_locale?: string, reference?: string, risk_correlation_id?: string, setup_future_usage?: null|string}, pix?: null|array{expires_after_seconds?: int, expires_at?: int, setup_future_usage?: string}, promptpay?: null|array{setup_future_usage?: string}, revolut_pay?: null|array{capture_method?: null|string, setup_future_usage?: null|string}, samsung_pay?: null|array{capture_method?: null|string}, sepa_debit?: null|array{mandate_options?: array{reference_prefix?: null|string}, setup_future_usage?: null|string, target_date?: string}, sofort?: null|array{preferred_language?: null|string, setup_future_usage?: null|string}, swish?: null|array{reference?: null|string, setup_future_usage?: string}, twint?: null|array{setup_future_usage?: string}, us_bank_account?: null|array{financial_connections?: array{filters?: array{account_subcategories?: string[]}, permissions?: string[], prefetch?: string[], return_url?: string}, mandate_options?: array{collection_method?: null|string}, networks?: array{requested?: string[]}, preferred_settlement_speed?: null|string, setup_future_usage?: null|string, target_date?: string, verification_method?: string}, wechat_pay?: null|array{app_id?: string, client?: string, setup_future_usage?: string}, zip?: null|array{setup_future_usage?: string}}, payment_method_types?: string[], radar_options?: array{session?: string}, receipt_email?: string, return_url?: string, setup_future_usage?: string, shipping?: array{address: array{city?: string, country?: string, line1?: string, line2?: string, postal_code?: string, state?: string}, carrier?: string, name: string, phone?: string, tracking_number?: string}, statement_descriptor?: string, statement_descriptor_suffix?: string, transfer_data?: array{amount?: int, destination: string}, transfer_group?: string, use_stripe_sdk?: bool} $params
     */
    public function confirmPaymentIntent(string $paymentIntentId, array $params): PaymentIntent
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

    /**
     * @param array{amount: int, application_fee_amount?: int, automatic_payment_methods?: array{allow_redirects?: string, enabled: bool}, capture_method?: string, confirm?: bool, confirmation_method?: string, confirmation_token?: string, currency: string, customer?: string, description?: string, error_on_requires_action?: bool, expand?: string[], mandate?: string, mandate_data?: null|array{customer_acceptance: array{accepted_at?: int, offline?: array{}, online?: array{ip_address: string, user_agent: string}, type: string}}, metadata?: \Stripe\StripeObject, off_session?: array|bool|string, on_behalf_of?: string, payment_method?: string, payment_method_configuration?: string, payment_method_data?: array{acss_debit?: array{account_number: string, institution_number: string, transit_number: string}, affirm?: array{}, afterpay_clearpay?: array{}, alipay?: array{}, allow_redisplay?: string, alma?: array{}, amazon_pay?: array{}, au_becs_debit?: array{account_number: string, bsb_number: string}, bacs_debit?: array{account_number?: string, sort_code?: string}, bancontact?: array{}, billie?: array{}, billing_details?: array{address?: null|array{city?: string, country?: string, line1?: string, line2?: string, postal_code?: string, state?: string}, email?: null|string, name?: null|string, phone?: null|string, tax_id?: string}, blik?: array{}, boleto?: array{tax_id: string}, cashapp?: array{}, customer_balance?: array{}, eps?: array{bank?: string}, fpx?: array{account_holder_type?: string, bank: string}, giropay?: array{}, grabpay?: array{}, ideal?: array{bank?: string}, interac_present?: array{}, kakao_pay?: array{}, klarna?: array{dob?: array{day: int, month: int, year: int}}, konbini?: array{}, kr_card?: array{}, link?: array{}, metadata?: \Stripe\StripeObject, mobilepay?: array{}, multibanco?: array{}, naver_pay?: array{funding?: string}, nz_bank_account?: array{account_holder_name?: string, account_number: string, bank_code: string, branch_code: string, reference?: string, suffix: string}, oxxo?: array{}, p24?: array{bank?: string}, pay_by_bank?: array{}, payco?: array{}, paynow?: array{}, paypal?: array{}, pix?: array{}, promptpay?: array{}, radar_options?: array{session?: string}, revolut_pay?: array{}, samsung_pay?: array{}, satispay?: array{}, sepa_debit?: array{iban: string}, sofort?: array{country: string}, swish?: array{}, twint?: array{}, type: string, us_bank_account?: array{account_holder_type?: string, account_number?: string, account_type?: string, financial_connections_account?: string, routing_number?: string}, wechat_pay?: array{}, zip?: array{}}, payment_method_options?: array{acss_debit?: null|array{mandate_options?: array{custom_mandate_url?: null|string, interval_description?: string, payment_schedule?: string, transaction_type?: string}, setup_future_usage?: null|string, target_date?: string, verification_method?: string}, affirm?: null|array{capture_method?: null|string, preferred_locale?: string, setup_future_usage?: string}, afterpay_clearpay?: null|array{capture_method?: null|string, reference?: string, setup_future_usage?: string}, alipay?: null|array{setup_future_usage?: null|string}, alma?: null|array{capture_method?: null|string}, amazon_pay?: null|array{capture_method?: null|string, setup_future_usage?: null|string}, au_becs_debit?: null|array{setup_future_usage?: null|string, target_date?: string}, bacs_debit?: null|array{mandate_options?: array{reference_prefix?: null|string}, setup_future_usage?: null|string, target_date?: string}, bancontact?: null|array{preferred_language?: string, setup_future_usage?: null|string}, billie?: null|array{capture_method?: null|string}, blik?: null|array{code?: string, setup_future_usage?: null|string}, boleto?: null|array{expires_after_days?: int, setup_future_usage?: null|string}, card?: null|array{capture_method?: null|string, cvc_token?: string, installments?: array{enabled?: bool, plan?: null|array{count?: int, interval?: string, type: string}}, mandate_options?: array{amount: int, amount_type: string, description?: string, end_date?: int, interval: string, interval_count?: int, reference: string, start_date: int, supported_types?: string[]}, moto?: bool, network?: string, request_extended_authorization?: string, request_incremental_authorization?: string, request_multicapture?: string, request_overcapture?: string, request_three_d_secure?: string, require_cvc_recollection?: bool, setup_future_usage?: null|string, statement_descriptor_suffix_kana?: null|string, statement_descriptor_suffix_kanji?: null|string, three_d_secure?: array{ares_trans_status?: string, cryptogram: string, electronic_commerce_indicator?: string, exemption_indicator?: string, network_options?: array{cartes_bancaires?: array{cb_avalgo: string, cb_exemption?: string, cb_score?: int}}, requestor_challenge_indicator?: string, transaction_id: string, version: string}}, card_present?: null|array{request_extended_authorization?: bool, request_incremental_authorization_support?: bool, routing?: array{requested_priority?: string}}, cashapp?: null|array{capture_method?: null|string, setup_future_usage?: null|string}, customer_balance?: null|array{bank_transfer?: array{eu_bank_transfer?: array{country: string}, requested_address_types?: string[], type: string}, funding_type?: string, setup_future_usage?: string}, eps?: null|array{setup_future_usage?: string}, fpx?: null|array{setup_future_usage?: string}, giropay?: null|array{setup_future_usage?: string}, grabpay?: null|array{setup_future_usage?: string}, ideal?: null|array{setup_future_usage?: null|string}, interac_present?: null|array{}, kakao_pay?: null|array{capture_method?: null|string, setup_future_usage?: null|string}, klarna?: null|array{capture_method?: null|string, preferred_locale?: string, setup_future_usage?: string}, konbini?: null|array{confirmation_number?: null|string, expires_after_days?: null|int, expires_at?: null|int, product_description?: null|string, setup_future_usage?: string}, kr_card?: null|array{capture_method?: null|string, setup_future_usage?: null|string}, link?: null|array{capture_method?: null|string, persistent_token?: string, setup_future_usage?: null|string}, mobilepay?: null|array{capture_method?: null|string, setup_future_usage?: string}, multibanco?: null|array{setup_future_usage?: string}, naver_pay?: null|array{capture_method?: null|string, setup_future_usage?: null|string}, nz_bank_account?: null|array{setup_future_usage?: null|string, target_date?: string}, oxxo?: null|array{expires_after_days?: int, setup_future_usage?: string}, p24?: null|array{setup_future_usage?: string, tos_shown_and_accepted?: bool}, pay_by_bank?: null|array{}, payco?: null|array{capture_method?: null|string}, paynow?: null|array{setup_future_usage?: string}, paypal?: null|array{capture_method?: null|string, preferred_locale?: string, reference?: string, risk_correlation_id?: string, setup_future_usage?: null|string}, pix?: null|array{expires_after_seconds?: int, expires_at?: int, setup_future_usage?: string}, promptpay?: null|array{setup_future_usage?: string}, revolut_pay?: null|array{capture_method?: null|string, setup_future_usage?: null|string}, samsung_pay?: null|array{capture_method?: null|string}, sepa_debit?: null|array{mandate_options?: array{reference_prefix?: null|string}, setup_future_usage?: null|string, target_date?: string}, sofort?: null|array{preferred_language?: null|string, setup_future_usage?: null|string}, swish?: null|array{reference?: null|string, setup_future_usage?: string}, twint?: null|array{setup_future_usage?: string}, us_bank_account?: null|array{financial_connections?: array{filters?: array{account_subcategories?: string[]}, permissions?: string[], prefetch?: string[], return_url?: string}, mandate_options?: array{collection_method?: null|string}, networks?: array{requested?: string[]}, preferred_settlement_speed?: null|string, setup_future_usage?: null|string, target_date?: string, verification_method?: string}, wechat_pay?: null|array{app_id?: string, client?: string, setup_future_usage?: string}, zip?: null|array{setup_future_usage?: string}}, payment_method_types?: string[], radar_options?: array{session?: string}, receipt_email?: string, return_url?: string, setup_future_usage?: string, shipping?: array{address: array{city?: string, country?: string, line1?: string, line2?: string, postal_code?: string, state?: string}, carrier?: string, name: string, phone?: string, tracking_number?: string}, statement_descriptor?: string, statement_descriptor_suffix?: string, transfer_data?: array{amount?: int, destination: string}, transfer_group?: string, use_stripe_sdk?: bool} $createPayload
     **/
    public function createPaymentIntent(array $createPayload): PaymentIntent
    {
        $this->pause();
        return new PaymentIntent('pi_stub_' . self::randomString());
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
        /** @psalm-suppress InvalidPropertyAssignmentValue */
        $confirmationToken->payment_method_preview = new StripeObject();

        /** @psalm-suppress InvalidPropertyAssignmentValue */
        $confirmationToken->payment_method_preview['type'] = 'card';

        /** @psalm-suppress InvalidPropertyAssignmentValue */
        $confirmationToken->payment_method_preview['card'] = ['brand' => 'discover', 'country' => 'GB'];

        return $confirmationToken;
    }

    public function createRegularGivingCustomerSession(StripeCustomerId $stripeCustomerId): CustomerSession
    {
        return $this->createCustomerSession($stripeCustomerId);
    }

    public function retrieveCharge(string $chargeId): Charge
    {
        throw new \Exception("Retrieve Charge not implemented in stub- not currently used in load tests");
    }

    public function retrieveBalanceTransaction(string $id): BalanceTransaction
    {
        throw new \Exception("Retrieve Balance Transaction not implemented in stub- not currently used in load tests");
    }

    public function createSetupIntent(StripeCustomerId $stripeCustomerId): SetupIntent
    {
        throw new \Exception("Create setup intent not implemented in stub - not currently used in load tests");
    }

    public function retrievePaymentMethod(StripeCustomerId $customerId, StripePaymentMethodId $methodId): PaymentMethod
    {
        throw new \Exception("Retrieve Payment Method not implemented in stub - not currently used in load tests");
    }

    public function detatchPaymentMethod(StripePaymentMethodId $paymentMethodId): void
    {
        throw new \Exception("Detatch Payment Method not implemented in stub - not currently used in load tests");
    }
}
