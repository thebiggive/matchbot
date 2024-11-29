<?php

declare(strict_types=1);

namespace MatchBot\IntegrationTests;

use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Tests\TestData;
use Prophecy\Argument;
use Psr\Http\Message\ResponseInterface;
use MatchBot\Client\Stripe;
use Stripe\PaymentIntent;

class CreateRegularGivingMandateTest extends IntegrationTest
{
    public function testItCreatesRegularGivingMandate(): void
    {
        // arrange
        $pencePerMonth = random_int(1_00, 500_00);

        $stripeProphecy = $this->prophesize(Stripe::class);
        $paymentIntentId = 'payment-intent-id-' . $this->randomString();
        $stripeProphecy->createPaymentIntent(
            Argument::that(fn(array $payload) => ($payload['amount'] === $pencePerMonth))
        )
            ->shouldBeCalledOnce()
            ->willReturn(new PaymentIntent($paymentIntentId));
        $this->getContainer()->set(Stripe::class, $stripeProphecy->reveal());

        $this->ensureDbHasDonorAccount();

        // act
        $response = $this->createRegularGivingMandate($pencePerMonth);

        // assert
        $this->assertSame(201, $response->getStatusCode());
        $mandateDatabaseRows = $this->db()->executeQuery(
            "SELECT * from RegularGivingMandate where amount_amountInPence = ?",
            [$pencePerMonth]
        )
            ->fetchAllAssociative();
        $this->assertNotEmpty($mandateDatabaseRows);
        $this->assertSame($pencePerMonth, $mandateDatabaseRows[0]['amount_amountInPence']);
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
