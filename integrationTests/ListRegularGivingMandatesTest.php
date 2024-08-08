<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Application\Actions\RegularGivingMandate\GetAllForUser;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use MatchBot\Domain\Money;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\Salesforce18Id;
use Ramsey\Uuid\Uuid;

/**
 * The action for listing a donation is pretty straighforward CRUD, so doesn't need much testing IMHO
 * but worth having something.
 */
class ListRegularGivingMandatesTest extends IntegrationTest
{
    /**
     * @psalm-suppress MixedArrayAccess - hard to avoid in integration testing like this.
     */
    public function testItListsRegularGivingMandate(): void
    {
        $personId = PersonId::of(Uuid::uuid4()->toString());

        // @todo create mandate for specific person and query by that in controller
        $this->addMandateToDb(/*$personId */);

        $allMandatesBody = (string) $this->requestFromController($personId)->getBody();

        $mandates = \json_decode($allMandatesBody, associative: true, flags: JSON_THROW_ON_ERROR)['mandates'];
        \assert(is_array($mandates));

        $this->assertCount(1, $mandates);
        $mandate = $mandates['0'];
        \assert(is_array($mandate));

        $this->assertEquals(
            [
                'id' => 'e552a93e-540e-11ef-98b2-3b7275661822',
                'donorId' => $personId->id,
                'campaignId' => 'DummySFIDCampaign0',
                'charityId' => 'DummySFIDCharity00',
                'amount' => [
                    'amountInPence' => 5_000_00,
                    'currency' => 'GBP',
                ],
                'schedule' => [
                    'type' => 'monthly',
                    'dayOfMonth' => 31, // i.e. donation to be taken on last day of each calendar month
                    'activeFrom' => '2024-08-06T00:00:00+00:00',
                ],
                'charityName' => 'Some Charity',
                'giftAid' => true,
                'status' => 'active',
                'tipAmount' => [
                    // todo before calling ticket done: confirm if we are taking tips like this for regular giving
                    'amountInPence' => 100,
                    'currency' => 'GBP',
                ],
                'createdTime' => '2024-08-06T00:00:00+00:00',
                'updatedTime' => '2024-08-06T00:00:00+00:00',
            ],
            $mandate
        );
    }

    public function requestFromController(PersonId $personId): \Psr\Http\Message\ResponseInterface
    {
        return $this->getService(GetAllForUser::class)->__invoke(
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

    private function addMandateToDb(): void
    {
        $em = $this->getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->executeStatement("DELETE FROM RegularGivingMandate where id > 0");

        $mandate = new RegularGivingMandate(
            PersonId::of(Uuid::uuid4()->toString()),
            amount: Money::fromPoundsGBP(5_000),
            campaignId: Salesforce18Id::of('DummySFIDCampaign0'),
            charityId: Salesforce18Id::of('DummySFIDCharity00'),
            giftAid: true,
        );

        $em->persist($mandate);
        $em->flush();
    }
}
