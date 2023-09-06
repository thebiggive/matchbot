<?php

use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\IntegrationTests\IntegrationTest;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Ramsey\Uuid\Uuid;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

class CreateDonationTest extends IntegrationTest
{
    use \Prophecy\PhpUnit\ProphecyTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->addCampaignAndCharityToDB($this->randomString());

        /** @var \DI\Container $container */
        $container = $this->getContainer();

        $donationClientProphecy = $this->prophesize(\MatchBot\Client\Donation::class);
        $donationClientProphecy->create(Argument::type(Donation::class))->willReturn($this->randomString());

        $container->set(\MatchBot\Client\Donation::class, $donationClientProphecy->reveal());

        $donationRepo = $container->get(DonationRepository::class);
        assert($donationRepo instanceof DonationRepository);
        $donationRepo->setClient($donationClientProphecy->reveal());
    }

    public function testItCreatesADonation(): void
    {
        // This test should be using fake stripe and salesforce clients, but things within our app,
        // from the HTTP router to the DB is using our real prod code.

        // act
        $response = $this->createDonation();

        // assert

        /** @var array{donation: array<string, string>} $decoded */
        $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('Some Charity', $decoded['donation']['charityName']);
        $this->assertNotEmpty($decoded['donation']['transactionId']);
        $uuid = $decoded['donation']['donationId'];
        $this->assertTrue(Uuid::isValid($uuid));

        $donationFetchedFromDB = $this->db()->fetchAssociative("SELECT * from Donation where Donation.uuid = '$uuid';");
        assert(is_array($donationFetchedFromDB));
        $this->assertSame('100.00', $donationFetchedFromDB['amount']);
    }

    public function testCannotCreateDonationWithNegativeTip(): void
    {
        // This test should be using fake stripe and salesforce clients, but things within our app,
        // from the HTTP router to the DB is using our real prod code.

        // act
        $response = $this->createDonation(tipAmount: -1);

        $this->assertSame(400, $response->getStatusCode());
    }

}
