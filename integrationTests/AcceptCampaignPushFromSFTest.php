<?php

namespace MatchBot\IntegrationTests;

use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Application\Auth\SalesforceAuthMiddleware;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\MetaCampaignRepository;
use MatchBot\Domain\MetaCampaignSlug;
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
                SalesforceAuthMiddleware::HEADER_NAME => TestCase::getSalesforceAuthValue($body),
            ],
            body: $body
        ));
        $this->assertSame(200, $response->getStatusCode());

        $campaign = $this->getContainer()->get(CampaignRepository::class)->findOneBySalesforceId($campaignSfId);
        $this->assertNotNull($campaign);

        $this->assertSame('Save Matchbot', $campaign->getCampaignName());
        $this->assertSame('Society for the advancement of bots and matches', $campaign->getCharity()->getName());
    }

    public function testItAcceptsAPushOfANewMetaCampaignFromSf(): void
    {
        $metaCampaignData = TestCase::META_CAMPAIGN_FROM_SALESFORCE;

        // randomise ID & slug to prevent duplicate issues
        $metaCampaignSfId = Salesforce18Id::ofCampaign(self::randomString());
        $slug = 'random-slug-' . self::randomString();
        $metaCampaignData['slug'] = $slug;

        $metaCampaignData['id'] = $metaCampaignSfId->value;

        $body = \json_encode(['campaign' => $metaCampaignData], JSON_THROW_ON_ERROR);

        $response = $this->getApp()->handle(new ServerRequest(
            method: 'PUT',
            uri: '/v1/campaigns/' . $metaCampaignSfId->value,
            headers: [
                SalesforceAuthMiddleware::HEADER_NAME => TestCase::getSalesforceAuthValue($body),
            ],
            body: $body
        ));
        $this->assertSame(200, $response->getStatusCode());

        $campaign = $this->getContainer()->get(MetaCampaignRepository::class)->getBySlug(MetaCampaignSlug::of($slug));
        $this->assertNotNull($campaign);

        $this->assertSame('This is a meta campaign', $campaign->getTitle());
    }
}
