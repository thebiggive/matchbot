<?php

namespace MatchBot\IntegrationTests;

use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\Application\Actions\Hooks\StripeTest;
use MatchBot\Tests\TestCase;

class AcceptCampaignPushFromSFTest extends \MatchBot\IntegrationTests\IntegrationTest
{
    public function testItAcceptsAPushOfANewCampaignFromSf(): void
    {
        $campaignData = TestCase::CAMPAIGN_FROM_SALESOFRCE;

        // randomise IDs to prevent duplicate issues
        $campaignSfId = Salesforce18Id::ofCampaign(self::randomString());
        $charitySfId = Salesforce18Id::ofCampaign(self::randomString());

        $campaignData['id'] = $campaignSfId->value;
        $campaignData['charity']['id'] = $charitySfId->value;

        $body = \json_encode(['campaign' => $campaignData], JSON_THROW_ON_ERROR);

        $response = $this->getApp()->handle(new ServerRequest(
            method: 'PUT',
            uri: '/v1/campaigns/' . $campaignSfId->value,
            headers: [
                'x-send-verify-hash' => TestCase::getSalesforceAuthValue($body),
            ],
            body: $body
        ));
        $this->assertSame(200, $response->getStatusCode());

        $campaign = $this->getContainer()->get(CampaignRepository::class)->findOneBySalesforceId($campaignSfId);
        $this->assertNotNull($campaign);

        $this->assertSame('Save Matchbot', $campaign->getCampaignName());
        $this->assertSame('Society for the advancement of bots and matches', $campaign->getCharity()->getName());
    }
}
