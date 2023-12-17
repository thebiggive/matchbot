<?php

namespace MatchBot\IntegrationTests;

use GuzzleHttp\Psr7\ServerRequest;
use Ramsey\Uuid\Uuid;

class CreateDonationTest extends IntegrationTest
{
    public function setUp(): void
    {
        parent::setUp();
        $this->addFundedCampaignAndCharityToDB($this->randomString());
        $this->setupFakeDonationClient();
    }

    public function testItCreatesADonation(): void
    {
        // This test should be using fake stripe and salesforce clients, but things within our app,
        // from the HTTP router to the DB is using our real prod code.

        // act
        $response = $this->createDonation(100);

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

    public function testITReturns400OnRequestToDonateNullAmount(): void
    {
        $campaignId = $this->setupNewCampaign();

        $response = $this->getApp()->handle(
            new ServerRequest(
                'POST',
                '/v1/donations',
                // The Symfony Serializer will throw an exception if the JSON document doesn't include all the required
                // constructor params of DonationCreate
                body: <<<EOF
                {
                    "currencyCode": "GBP",
                    "donationAmount": null,
                    "projectId": "$campaignId",
                    "psp": "stripe",
                    "tipAmount": "0"
                }
            EOF,
                serverParams: ['REMOTE_ADDR' => '127.0.0.1']
            )
        );

        $this->assertSame(400, $response->getStatusCode());
    }

}
