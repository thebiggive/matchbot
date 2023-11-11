<?php

namespace MatchBot\IntegrationTests;

use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Prophecy\Argument;
use Psr\Http\Message\ResponseInterface;
use Stripe\PaymentIntent;

class ConfirmDonationTest extends IntegrationTest
{
    public function setUp(): void
    {
        parent::setUp();
        $this->addCampaignAndCharityToDB($this->randomString());
        $this->setupFakeDonationClient();
    }

    public function testItConfirmsADonationAndSavesFeeInternally(): void
    {
        // This test should be using fake stripe and salesforce clients, but things within our app,
        // from the HTTP router to the DB is using our real prod code.

        // arrange
        $response = $this->createDonation(100);
        $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $uuid = $decoded['donation']['donationId'];

        // act
        $confirmResponse = $this->confirmDonation($uuid);

        // assert

        /** @var array{donation: array<string, string>} $decoded */
        $decoded = json_decode((string)$confirmResponse->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(201, $confirmResponse->getStatusCode());
        $this->assertSame('Some Charity', $decoded['donation']['charityName']);

        $donationFetchedFromDB = $this->db()->fetchAssociative("SELECT * from Donation where Donation.uuid = '$uuid';");
        assert(is_array($donationFetchedFromDB));
        $this->assertSame('100.00', $donationFetchedFromDB['amount']);
        $this->assertSame('1.00', $donationFetchedFromDB['charityFee']);
    }

    private function confirmDonation(string $donationUuid): ResponseInterface
    {
        $paymentIntentId = $this->randomString();

        $stripePaymentIntent = new PaymentIntent($paymentIntentId);
        $stripePaymentIntent->client_secret = 'any string, doesnt affect test';
        $stripePaymentIntentsProphecy = $this->setUpFakeStripeClient();

        $stripePaymentIntentsProphecy->update(Argument::type('array'))
            ->willReturn($stripePaymentIntent);
        $stripePaymentIntentsProphecy->confirm(Argument::type('array'))
            ->willReturn($stripePaymentIntent);

        /** @var \DI\Container $container */
        $container = $this->getContainer();

        $donationClientProphecy = $this->prophesize(\MatchBot\Client\Donation::class);
        $donationClientProphecy->put(Argument::type(Donation::class))->willReturn(true);

        $container->set(\MatchBot\Client\Donation::class, $donationClientProphecy->reveal());

        $donationRepo = $container->get(DonationRepository::class);
        assert($donationRepo instanceof DonationRepository);
        $donationRepo->setClient($donationClientProphecy->reveal());

        return $this->getApp()->handle(
            new ServerRequest(
                'POST',
                "/v1/donations/$donationUuid/confirm",
                body: <<<EOF
                {
                    "stripePaymentMethodId": "pm_test_987"
                }
            EOF,
                serverParams: ['REMOTE_ADDR' => '127.0.0.1']
            )
        );
    }
}
