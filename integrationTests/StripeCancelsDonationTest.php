<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Application\Settings;
use MatchBot\Domain\DonationStatus;
use MatchBot\Tests\Application\Actions\Hooks\StripeTest;

class StripeCancelsDonationTest extends IntegrationTest
{
    public function testStripeCanCancelDonation(): void
    {
        /**
         * @psalm-suppress MixedArrayAccess
         * @var array<string,string> $donation
         */
        $donation = json_decode(
            (string)$this->createDonation(100)->getBody(),
            true,
            flags: \JSON_THROW_ON_ERROR
        )['donation'];

        // Clear the previous copy of the donation, otherwise loading the donation with a lock fails, probably
        // because of https://github.com/doctrine/orm/issues/9505 combined with the test creating and
        // then patching the donation in one thread. Doctrine doesn't recognise the donation
        // to cancel as the same and gets an error trying to set the readonly $amount property.
        // Having a clear EM at the start of each web request is closer to how the real app runs anyway.
        $this->getService(EntityManagerInterface::class)->clear();

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
