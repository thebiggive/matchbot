<?php

namespace MatchBot\IntegrationTests;

use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Application\Settings;
use MatchBot\Domain\DonationStatus;
use MatchBot\Tests\Application\Actions\Hooks\StripeTest;

class StripeCancelsDonationTest extends IntegrationTest
{
    public function testStripeCanCancelDonation(): void
    {
        $this->markTestSkipped('See comment in StripePaymentsUpdate::handlePaymentIntentCancelled()');

        /**
         * @psalm-suppress MixedArrayAccess
         * @var array<string,string> $donation
         */
        $donation = json_decode(
            (string)$this->createDonation(100)->getBody(),
            true,
            flags: \JSON_THROW_ON_ERROR
        )['donation'];

        $this->sendCancellationWebhookFromStripe($donation['transactionId']);

        $this->assertSame(
            DonationStatus::Cancelled->value,
            $this->db()->fetchOne(
                'SELECT donationStatus from Donation where uuid = ?',
                [$donation['donationId']]
            )
        );
    }

    /**
     * @psalm-suppress UnusedMethod will be used if we fix test
     */
    private function sendCancellationWebhookFromStripe(string $transactionId): void
    {
        $paymentIntentId = $transactionId;

        $webhookSecret = $this->getContainer()->get(Settings::class)->stripe['accountWebhookSecret'];

        $requestBody = json_encode(
            [
            'type' => 'payment_intent.canceled',
            'livemode' => false,
            'data' => [
                "client_secret" => $webhookSecret,
                'object' => [
                    'object' => 'payment_intent',
                    'id' => $paymentIntentId,
                    'livemode' => false
                ]
            ]
            ],
            \JSON_THROW_ON_ERROR
        );


        $this->getApp()->handle(new ServerRequest(
            method: 'POST',
            uri: '/hooks/stripe',
            headers: [
                'stripe-signature' => StripeTest::generateSignature((string)time(), $requestBody, $webhookSecret),
            ],
            body: $requestBody
        ));
    }
}
