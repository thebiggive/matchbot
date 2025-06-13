<?php

namespace MatchBot\IntegrationTests;

use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\Application\Auth\SalesforceAuthMiddleware;
use MatchBot\Domain\CharityRepository;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;

class AcceptCharityPushFromSFTest extends \MatchBot\IntegrationTests\IntegrationTest
{
    public function testItAcceptsAPushOfANewCharityFromSf(): void
    {
        // Extract charity data from the campaign data
        $charityData = TestCase::CAMPAIGN_FROM_SALESOFRCE['charity'];

        // Randomize ID to prevent duplicate issues
        $charitySfId = Salesforce18Id::ofCharity(self::randomString());
        $charityData['id'] = $charitySfId->value;

        $body = \json_encode(['charity' => $charityData], JSON_THROW_ON_ERROR);

        $response = $this->getApp()->handle(new ServerRequest(
            method: 'PUT',
            uri: '/v1/charities/' . $charitySfId->value,
            headers: [
                SalesforceAuthMiddleware::HEADER_NAME => TestCase::getSalesforceAuthValue($body),
            ],
            body: $body
        ));
        $this->assertSame(200, $response->getStatusCode());

        $charity = $this->getContainer()->get(CharityRepository::class)->findOneBySalesforceId($charitySfId);
        $this->assertNotNull($charity);

        $this->assertSame('Society for the advancement of bots and matches', $charity->getName());
        $this->assertSame('acc_123456', $charity->getStripeAccountId());
    }

    public function testItUpdatesAnExistingCharityFromSf(): void
    {
        // First create a charity
        $charitySfId = Salesforce18Id::ofCharity(self::randomString());
        $charity = TestCase::someCharity(
            salesforceId: $charitySfId,
            name: 'Original Charity Name',
            stripeAccountId: 'original_stripe_account'
        );

        $this->em->persist($charity);
        $this->em->flush();

        // Now update it
        $charityData = TestCase::CAMPAIGN_FROM_SALESOFRCE['charity'];
        $charityData['id'] = $charitySfId->value;
        $charityData['name'] = 'Updated Charity Name';

        $body = \json_encode(['charity' => $charityData], JSON_THROW_ON_ERROR);

        $response = $this->getApp()->handle(new ServerRequest(
            method: 'PUT',
            uri: '/v1/charities/' . $charitySfId->value,
            headers: [
                SalesforceAuthMiddleware::HEADER_NAME => TestCase::getSalesforceAuthValue($body),
            ],
            body: $body
        ));
        $this->assertSame(200, $response->getStatusCode());

        // Clear entity manager to ensure we get fresh data
        $this->em->clear();

        $updatedCharity = $this->getContainer()->get(CharityRepository::class)->findOneBySalesforceId($charitySfId);
        $this->assertNotNull($updatedCharity);

        $this->assertSame('Updated Charity Name', $updatedCharity->getName());
        $this->assertSame('acc_123456', $updatedCharity->getStripeAccountId());
    }
}
