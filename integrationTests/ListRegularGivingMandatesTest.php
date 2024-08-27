<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Application\Actions\RegularGivingMandate\GetAllForUser;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\Money;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\Salesforce18Id;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

/**
 * The action for listing a donation is pretty straighforward CRUD, so doesn't need much testing IMHO
 * but worth having something.
 */
class ListRegularGivingMandatesTest extends IntegrationTest
{
    private PersonId $donorId;

    public function setUp(): void
    {
        parent::setUp();
        $this->donorId = PersonId::of(Uuid::uuid4()->toString());
    }

    /**
     * @psalm-suppress MixedArrayAccess - hard to avoid in integration testing like this.
     */
    public function testItListsRegularGivingMandate(): void
    {
        $campaignSfId = $this->randomString();
        $charitySfId = $this->randomString();

        $charityName = "Charity Name " . $this->randomString();

        $this->addCampaignAndCharityToDB(
            campaignSfId: $campaignSfId,
            charitySfId: $charitySfId,
            charityName: $charityName
        );

        $uuid = $this->addMandateToDb($this->donorId, $campaignSfId, $charitySfId, active: true);
        $this->addMandateToDb($this->donorId, $campaignSfId, $charitySfId, active: false);

        $allMandatesBody = (string) $this->requestFromController($this->donorId)->getBody();

        $mandates = \json_decode($allMandatesBody, associative: true, flags: JSON_THROW_ON_ERROR)['mandates'];
        \assert(is_array($mandates));

        $this->assertCount(1, $mandates);
        $mandate = $mandates['0'];
        \assert(is_array($mandate));

        $this->assertEquals(
            [
                'id' => $uuid->toString(),
                'donorId' => $this->donorId->id,
                'campaignId' => $campaignSfId,
                'charityId' => $charitySfId,
                'amount' => [
                    'amountInPence' => 500_00,
                    'currency' => 'GBP',
                ],
                'schedule' => [
                    'type' => 'monthly',
                    'dayOfMonth' => 28,
                    'activeFrom' => '2024-08-06T00:00:00+00:00',
                ],
                'charityName' => $charityName,
                'giftAid' => true,
                'status' => 'active',
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

    private function addMandateToDb(
        PersonId $personId,
        string $campaignId,
        string $charityId,
        bool $active
    ): UuidInterface {
        $mandate = new RegularGivingMandate(
            donorId: $personId,
            amount: Money::fromPoundsGBP(500),
            campaignId: Salesforce18Id::ofCampaign($campaignId),
            charityId: Salesforce18Id::ofCharity($charityId),
            giftAid: true,
            dayOfMonth: DayOfMonth::of(28),
        );

        if ($active) {
            $mandate->activate(new \DateTimeImmutable('2024-08-06T00:00:00+00:00'));
        }

        $em = $this->getContainer()->get(EntityManagerInterface::class);
        $em->persist($mandate);
        $em->flush();

        return $mandate->uuid;
    }
}
