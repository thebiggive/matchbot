<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Application\Actions\Donations\GetAllForUser;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;

/**
 * The action for listing a donation is pretty straighforward CRUD, so doesn't need much testing IMHO
 * but worth having something.
 */
class ListDonationsTest extends IntegrationTest
{
    #[\Override]
    public function setUp(): void
    {
        parent::setUp();
    }

    /**
     * @psalm-suppress MixedArrayAccess - hard to avoid in integration testing like this.
     */
    public function testItReturnsAListWithCollectedDonation(): void
    {
        // arrange
        $donationResponse = $this->createDonation(tipAmount: 10, amountInPounds: 50);
        $donationUUID = json_decode((string) $donationResponse->getBody(), associative: true)['donation']['donationId'];
        \assert(is_string($donationUUID));
        $stripeID = 'cus_' . $this->randomString();

        $this->db()->executeStatement(
            "UPDATE Donation set donationStatus = 'Collected', pspCustomerId = ? where uuid = ?",
            [$stripeID, $donationUUID]
        );

        $this->getService(EntityManagerInterface::class)->clear();

        // act
        $allDonationsBody = (string) $this->requestFromController($stripeID)->getBody();

        // assert
        $allDonations = \json_decode($allDonationsBody, associative: true, flags: \JSON_THROW_ON_ERROR)['donations'];
        \assert(is_array($allDonations));

        $this->assertCount(1, $allDonations);
        $donation = $allDonations['0'];
        \assert(is_array($donation));

        $this->assertSame($donation['donationId'], $donationUUID);
        $this->assertSame($donation['pspCustomerId'], $stripeID);
    }

    public function requestFromController(string $stripeID): \Psr\Http\Message\ResponseInterface
    {
        return $this->getService(GetAllForUser::class)->__invoke(
            (new ServerRequest(
                method: 'GET',
                uri: '',
                serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            ))->withAttribute(
                PersonManagementAuthMiddleware::PSP_ATTRIBUTE_NAME,
                $stripeID
            ),
            new \Slim\Psr7\Response(),
            []
        );
    }
}
