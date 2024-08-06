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
    /**
     * @psalm-suppress MixedArrayAccess - hard to avoid in integration testing like this.
     */
    public function testItListsRegularGivingMandate(): void
    {
        $personId = PersonId::of(Uuid::uuid4()->toString());

        $allMandatesBody = (string) $this->requestFromController($personId)->getBody();

        $mandates = \json_decode($allMandatesBody, associative: true, flags: JSON_THROW_ON_ERROR)['mandates'];
        \assert(is_array($mandates));

        $this->assertCount(1, $mandates);
        $mandate = $mandates['0'];
        \assert(is_array($mandate));

        $this->assertEqualsCanonicalizing(
            [
                'donorId' => $personId->value,
                'campaignId' => 'DummySFIDCampaign0',
                'charityId' => 'DummySFIDCharity00',
                'amount' => [
                    'amountInPence' => 600,
                    'currency' => 'GBP',
                ],
                // we could have a choice of payment card recorded here but I think its probably fine to say that that's
                // attached to the Donor's account, not the mandate. I.e. they can set their default card and it will
                // apply to all mandates.
                'schedule' => [
                    'type' => 'monthly',
                    'dayOfMonth' => 31, // i.e. donation to be taken on last day of each calendar month
                    'activeFrom' => '2024-08-06T00:00:00+00:00',
                ],
                // I'm not sure if we'll details of the donor like their name and home address
                // recorded as part of the mandate. I think probably not - we have that on the person
                // record and on each individual donation.
                'charityName' => 'Some Charity',
                'createdTime' => '2024-08-06T00:00:00+00:00',
                'giftAid' => true, // not 100% sure yet if GA will be an option on regular giving, assuming it will.
                'status' => 'active',

                // also guessing tipAmount will be an option on regular giving, and we'll apply same tip to
                // every donation
                'tipAmount' => [
                    'amountInPence' => 100,
                    'currency' => 'GBP',
                ],
                'updatedTime' => '2024-08-06T00:00:00+00:00',

            ],
            $mandate
        );
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
