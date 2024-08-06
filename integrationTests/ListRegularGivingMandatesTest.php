<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Application\Actions\RegularGivingMandate;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use MatchBot\Domain\PersonId;
use Ramsey\Uuid\Uuid;

/**
 * The action for listing a donation is pretty straighforward CRUD, so doesn't need much testing IMHO
 * but worth having something.
 */
class ListRegularGivingMandatesTest extends IntegrationTest
{
    public function setUp(): void
    {
        parent::setUp();
    }

    /**
     * @psalm-suppress MixedArrayAccess - hard to avoid in integration testing like this.
     */
    public function testItReturnsAListWithCollectedDonation(): void
    {
//        $this->db()->executeStatement(
//            "",
//            []
//        );

        $this->getService(EntityManagerInterface::class)->clear();

        $personId = PersonId::of(Uuid::uuid4()->toString());

        // act
        $allMandatesBody = (string) $this->requestFromController($personId)->getBody();

        // assert
        $allMandatest = \json_decode($allMandatesBody, associative: true, flags: JSON_THROW_ON_ERROR)['mandates'];
        \assert(is_array($allMandatest));

        $this->assertCount(1, $allMandatest);
        $mandate = $allMandatest['0'];
        \assert(is_array($mandate));

        $this->assertSame($mandate['donorId'], $personId->value);
    }

    public function requestFromController(PersonId $personId): \Psr\Http\Message\ResponseInterface
    {
        return $this->getService(RegularGivingMandate\GetAllForUser::class)->__invoke(
            (new ServerRequest(
                method: 'GET',
                uri: '',
                serverParams: ['REMOTE_ADDR' => '127.0.0.1'],
            ))->withAttribute(
                PersonManagementAuthMiddleware::PERSON_ID_ATTRIBUTE_NAME,
                $personId
            ),
            new \Slim\Psr7\Response(),
            []
        );
    }
}
