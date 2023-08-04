<?php

use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
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
            (string)$this->createDonation()->getBody(),
            true,
            flags: JSON_THROW_ON_ERROR
        )['donation'];

        $paymentIntentId = $donation['transactionId'];


        /** @var string $webhookSecret */
        $webhookSecret = $this->getContainer()->get('settings')['stripe']['accountWebhookSecret'];
//        var_dump(compact('webhookSecret'));

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


        $hookResponse = $this->getApp()->handle(new ServerRequest(
            method: 'POST',
            uri: '/hooks/stripe',
            headers: ['stripe-signature' => StripeTest::generateSignature((string) time(), $requestBody, $webhookSecret)],
            body: $requestBody
            )
        );

//        echo "----------- hook response ------- \n";
//        var_dump($hookResponse->getStatusCode());
//        var_dump($hookResponse->getHeaders());
//        var_dump($hookResponse->getBody()->getContents());

        /** @psalm-suppress MixedArrayAccess */
        $_donationRow = $this->db()->fetchAssociative("SELECT * from Donation where Donation.id = ?", [$donation['donationId']]);

        /** @psalm-suppress MixedArrayAccess */
        $this->assertSame('Cancelled', $this->db()->fetchOne('SELECT donationStatus from Donation where uuid = ?', [$donation['donationId']]));
    }

    private function createDonation(): \Psr\Http\Message\ResponseInterface
    {
        // This test should be using fake stripe and salesforce clients, but things within our app,
        // from the HTTP router to the DB is using our real prod code.

        // arrange
        $campaignId = $this->randomString();
        $paymentIntentId = $this->randomString();

        $this->addCampaignAndCharityToDB($campaignId);

        $stripePaymentIntent = new PaymentIntent($paymentIntentId);
        $stripePaymentIntent->client_secret = 'any string, doesnt affect test';
        $stripePaymentIntentsProphecy = $this->setUpFakeStripeClient();

        $stripePaymentIntentsProphecy->create(Argument::type('array'))
            ->shouldBeCalled()
            ->willReturn($stripePaymentIntent);

        /** @var \DI\Container $container */
        $container = $this->getContainer();

        $donationClientProphecy = $this->prophesize(\MatchBot\Client\Donation::class);
        $donationClientProphecy->create(Argument::type(Donation::class))->willReturn($this->randomString());

        $container->set(\MatchBot\Client\Donation::class, $donationClientProphecy->reveal());

        $donationRepo = $container->get(DonationRepository::class);
        assert($donationRepo instanceof DonationRepository);
        $donationRepo->setClient($donationClientProphecy->reveal());

        return $this->getApp()->handle(
            new ServerRequest(
                'POST',
                '/v1/donations',
                // The Symfony Serializer will throw an exception if the JSON document doesn't include all the required
                // constructor params of DonationCreate
                body: <<<EOF
                {
                    "currencyCode": "GBP",
                    "donationAmount": "100",
                    "projectId": "$campaignId",
                    "psp": "stripe" 
                }
            EOF,
                serverParams: ['REMOTE_ADDR' => '127.0.0.1']
            )
        );
    }

    public function addCampaignAndCharityToDB(string $campaginId): void
    {
        $charityId = random_int(1000, 100000);
        $charitySfID = $this->randomString();
        $charityStripeId = $this->randomString();

        $this->db()->executeStatement(<<<EOF
            INSERT INTO Charity (id, name, salesforceId, salesforceLastPull, createdAt, updatedAt, donateLinkId, stripeAccountId, hmrcReferenceNumber, tbgClaimingGiftAid, regulator, regulatorNumber) 
            VALUES ($charityId, 'Some Charity', '$charitySfID', '2023-01-01', '2093-01-01', '2023-01-01', 1, '$charityStripeId', null, 0, null, null)
            EOF
);
        $this->db()->executeStatement(<<<EOF
            INSERT INTO Campaign (charity_id, name, startDate, endDate, isMatched, salesforceId, salesforceLastPull, createdAt, updatedAt, currencyCode, feePercentage) 
            VALUES ('$charityId', 'some charity', '2023-01-01', '2093-01-01', 0, '$campaginId', '2023-01-01', '2023-01-01', '2023-01-01', 'GBP', 0)
            EOF
);
    }

    /**
     * @return ObjectProphecy<\Stripe\Service\PaymentIntentService>
     */
    public function setUpFakeStripeClient(): ObjectProphecy
    {
        $stripePaymentIntentsProphecy = $this->prophesize(\Stripe\Service\PaymentIntentService::class);

        $fakeStripeClient = $this->fakeStripeClient(
            $this->prophesize(\Stripe\Service\PaymentMethodService::class),
            $this->prophesize(\Stripe\Service\CustomerService::class),
            $stripePaymentIntentsProphecy,
        );

        /** @var \DI\Container $container */
        $container = $this->getContainer();
        $container->set(StripeClient::class, $fakeStripeClient);
        return $stripePaymentIntentsProphecy;
    }

    public function randomString(): string
    {
        return substr(Uuid::uuid4()->toString(), 0, 18);
    }
}
