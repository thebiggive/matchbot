<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\IntegrationTests\IntegrationTest;
use MatchBot\Tests\TestData;
use Psr\Http\Message\ResponseInterface;

class CreateRegularGivingMandateTest extends IntegrationTest
{
    public function testItCreatesRegularGivingMandate(): void
    {
        $pencePerMonth = random_int(1_00, 500_00);

        $response = $this->createRegularGivingMandate($pencePerMonth);

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

        $this->addFundedCampaignAndCharityToDB($campaignId);

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
}
