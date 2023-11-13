<?php

namespace MatchBot\IntegrationTests;

use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use MatchBot\Application\Actions\DonorAccount;

class CreateDonorAccountTest extends IntegrationTest
{
    public function setUp(): void
    {
        parent::setUp();
    }

    public function testItcreatesADonorAccount(): void
    {
        $stripeID = "cus_" . random_int(1000, 9999);
        $emailAddress = 'donor' . random_int(1000, 9999) . '@eamil-for-generous-people.com';

        $response = $this->requestFromController(
            body: json_encode([
                'emailAddress' => $emailAddress,
                'donorName' => [
                    'firstName' => 'Joe',
                    'lastName' => 'Bloggs',
                    ],
                ]),
            stripeID: $stripeID
        );

        $this->assertSame(201, $response->getStatusCode());

        $donationFetchedFromDB = $this->db()->fetchAssociative(
            "SELECT email, stripeCustomerId from DonorAccount WHERE stripeCustomerID = ?",
            [$stripeID]
        );
        assert(is_array($donationFetchedFromDB));

        $this->assertSame(
            [
                'email' => $emailAddress,
                'stripeCustomerId' => $stripeID,
            ],
            $donationFetchedFromDB
        );
    }

    public function requestFromController(string $body, string $stripeID): \Psr\Http\Message\ResponseInterface
    {
        return $this->getService(DonorAccount\Create::class)->__invoke(
            (new ServerRequest(
                method: 'POST',
                uri: '',
                serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
                body: $body,
            ))->withAttribute(
                PersonManagementAuthMiddleware::PSP_ATTRIBUTE_NAME,
                $stripeID
            ),
            new \Slim\Psr7\Response(),
            []
        );
    }
}
