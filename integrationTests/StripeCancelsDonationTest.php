<?php

use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use MatchBot\IntegrationTests\IntegrationTest;
use MatchBot\Tests\Application\Actions\Hooks\StripeTest;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Ramsey\Uuid\Uuid;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

class StripeCancelsDonationTest extends IntegrationTest
{
    use \Prophecy\PhpUnit\ProphecyTrait;

    public function testStripeCanCancelDonation(): void
    {
        /**
         * @psalm-suppress MixedArrayAccess
         * @var array<string,string> $donation
         */
        $donation = json_decode(
            (string)$this->createDonation(100)->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR
        )['donation'];

        $this->sendCancellationWebhookFromStripe($donation['transactionId']);

        $this->assertSame(DonationStatus::Cancelled->value, $this->db()->fetchOne('SELECT donationStatus from Donation where uuid = ?', [$donation['donationId']]));
    }

    private function sendCancellationWebhookFromStripe(string $transactionId): void
    {
        $paymentIntentId = $transactionId;

        /**
         * @psalm-suppress MixedArrayAccess
         * @var string $webhookSecret
         */
        $webhookSecret = $this->getContainer()->get('settings')['stripe']['accountWebhookSecret'];

        $requestBody = json_encode([
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
        ]);


        $this->getApp()->handle(new ServerRequest(
                method: 'POST',
                uri: '/hooks/stripe',
                headers: ['stripe-signature' => StripeTest::generateSignature((string)time(), $requestBody, $webhookSecret)],
                body: $requestBody
            )
        );
    }
}
