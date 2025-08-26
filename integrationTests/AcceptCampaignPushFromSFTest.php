<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Application\Auth\SalesforceAuthMiddleware;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CharityRepository;
use MatchBot\Domain\MetaCampaignRepository;
use MatchBot\Domain\MetaCampaignSlug;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\Application\Actions\Hooks\StripeTest;
use MatchBot\Tests\TestCase;
use MatchBot\Client;

/**
 * @psalm-import-type SFCampaignApiResponse from Client\Campaign
 */
class AcceptCampaignPushFromSFTest extends \MatchBot\IntegrationTests\IntegrationTest
{
    public function testItAcceptsAPushOfANewMetaCampaignFromSf(): void
    {
        $metaCampaignData = TestCase::META_CAMPAIGN_FROM_SALESFORCE;

        // randomise ID & slug to prevent duplicate issues
        $metaCampaignSfId = Salesforce18Id::ofCampaign(self::randomString());
        $slug = 'random-slug-' . self::randomString();
        $metaCampaignData['slug'] = $slug;

        $metaCampaignData['id'] = $metaCampaignSfId->value;

        $body = \json_encode(['campaigns' => [$metaCampaignData]], JSON_THROW_ON_ERROR);

        $response = $this->getApp()->handle(new ServerRequest(
            method: 'POST',
            uri: '/v1/campaigns/upsert-many',
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

    public function testItAcceptsAPushOfNewCampaignsFromSf(): void
    {
        $campaignData = ['campaigns' => [
            TestCase::CAMPAIGN_FROM_SALESFORCE,
            TestCase::META_CAMPAIGN_FROM_SALESFORCE,
        ]];

        // randomise IDs to prevent duplicate issues
        $campaignSfId = Salesforce18Id::ofCampaign(self::randomString());
        $metaCampaignSfId = Salesforce18Id::ofMetaCampaign(self::randomString());
        $charitySfId = Salesforce18Id::ofCampaign(self::randomString());


        $campaignData['campaigns'][0]['id'] = $campaignSfId->value;
        $campaignData['campaigns'][0]['charity']['id'] = $charitySfId->value;

        $this->persistCharityToDb($campaignData['campaigns'][0]);

        $campaignData['campaigns'][1]['id'] = $metaCampaignSfId->value;

        $body = \json_encode($campaignData, JSON_THROW_ON_ERROR);

        $response = $this->getApp()->handle(new ServerRequest(
            method: 'POST',
            uri: '/v1/campaigns/upsert-many',
            headers: [
                SalesforceAuthMiddleware::HEADER_NAME => TestCase::getSalesforceAuthValue($body),
            ],
            body: $body
        ));
        $this->assertSame(200, $response->getStatusCode());

        $campaign = $this->getContainer()->get(CampaignRepository::class)->findOneBySalesforceId($campaignSfId);
        $this->assertNotNull($campaign);

        $metaCampaign = $this->getContainer()->get(MetaCampaignRepository::class)->findOneBySalesforceId($metaCampaignSfId);
        $this->assertNotNull($metaCampaign);

        $this->assertSame('Save Matchbot', $campaign->getCampaignName());
        $this->assertSame('Society for the advancement of bots and matches', $campaign->getCharity()->getName());

        $this->assertSame('This is a meta campaign', $metaCampaign->getTitle());
    }

    /**
     * Persisting Charity in the database. Assuming that the charity should be in Matchbot before the Campaign is created.
     *
     * @param SFCampaignApiResponse $campaignData
     */
    public function persistCharityToDb(array $campaignData): void
    {
        $charity = $this->getService(CampaignRepository::class)->newCharityFromCampaignData($campaignData);
        $em = $this->getService(EntityManagerInterface::class);
        $em->persist($charity);
        $em->flush();
    }
}
