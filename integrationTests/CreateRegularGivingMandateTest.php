<?php

declare(strict_types=1);

namespace MatchBot\IntegrationTests;

use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Client\Mailer;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Tests\TestData;
use Prophecy\Argument;
use Psr\Http\Message\ResponseInterface;
use MatchBot\Client\Stripe;
use Stripe\PaymentIntent;

class CreateRegularGivingMandateTest extends IntegrationTest
{
    public function setUp(): void
    {
        parent::setUp();
        $this->getContainer()->set(Mailer::class, $this->createStub(Mailer::class));
    }
    public function testItCreatesRegularGivingMandate(): void
    {
        // arrange
        $pencePerMonth = random_int(1, 500) * 100;

        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->createPaymentIntent(
            Argument::that(fn(array $payload) => ($payload['amount'] === $pencePerMonth))
        )
            ->shouldBeCalledTimes(3)
            ->will(fn() => new PaymentIntent('payment-intent-id-' . IntegrationTest::randomString()));
        $this->getContainer()->set(Stripe::class, $stripeProphecy->reveal());

        $this->ensureDbHasDonorAccount();

        // act
        $response = $this->createRegularGivingMandate($pencePerMonth);

        // assert
        $this->assertSame(201, $response->getStatusCode());
        $mandateDatabaseRows = $this->db()->executeQuery(
            "SELECT * from RegularGivingMandate where donationAmount_amountInPence = ?",
            [$pencePerMonth]
        )
            ->fetchAllAssociative();
        $this->assertNotEmpty($mandateDatabaseRows);
        $this->assertSame($pencePerMonth, $mandateDatabaseRows[0]['donationAmount_amountInPence']);

        $donationDatabaseRows = $this->db()->executeQuery(
            "SELECT * from Donation where Donation.mandate_id = ? ORDER BY mandateSequenceNumber asc",
            [$mandateDatabaseRows[0]['id']]
        )->fetchAllAssociative();

        $this->assertCount(3, $donationDatabaseRows);

        $this->assertSame('Pending', $donationDatabaseRows[0]['donationStatus']); // see @todo in SUT - should be collected not pending
        $this->assertSame('PreAuthorized', $donationDatabaseRows[1]['donationStatus']);
        $this->assertSame('PreAuthorized', $donationDatabaseRows[2]['donationStatus']);

        $this->assertNull($donationDatabaseRows[0]['preAuthorizationDate']);
        $this->assertNotNull($donationDatabaseRows[1]['preAuthorizationDate']);
        $this->assertNotNull($donationDatabaseRows[2]['preAuthorizationDate']);

        $this->assertEquals((float)($pencePerMonth / 100), $donationDatabaseRows[0]['amount']);
        $this->assertEquals((float)($pencePerMonth / 100), $donationDatabaseRows[1]['amount']);
        $this->assertEquals((float)($pencePerMonth / 100), $donationDatabaseRows[2]['amount']);
    }


    protected function createRegularGivingMandate(
        int $pencePerMonth
    ): ResponseInterface {
        $campaignId = $this->randomString();

        $this->addFundedCampaignAndCharityToDB($campaignId, isRegularGiving: true);

        return $this->getApp()->handle(
            new ServerRequest(
                'POST',
                TestData\Identity::getTestPersonMandateEndpoint(),
                headers: [
                    'X-Tbg-Auth' => TestData\Identity::getTestIdentityTokenComplete(),
                ],
                // The Symfony Serializer will throw an exception if the JSON document doesn't include all the required
                // constructor params of DonationCreate
                body: <<<EOF
                {
                    "currency": "GBP",
                    "amountInPence": $pencePerMonth,
                    "dayOfMonth": 1,
                    "giftAid": false,
                    "campaignId": "$campaignId"
                }
            EOF,
                serverParams: ['REMOTE_ADDR' => '127.0.0.1']
            )
        );
    }

    private function ensureDbHasDonorAccount(): void
    {
        $donorAccount = TestData\Identity::donorAccount();
        $repository = $this->getService(DonorAccountRepository::class);

        // previously I did a try-catch for UniqueConstraintViolationException but that's no good,
        // the entity manager goes away when it throws that.

        if (! $repository->findByPersonId($donorAccount->id())) {
            $repository->save($donorAccount);
        }
    }
}
