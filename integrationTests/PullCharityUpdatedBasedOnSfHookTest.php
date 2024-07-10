<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManager;
use Laminas\Diactoros\ServerRequest;
use MatchBot\Domain\Charity;
use MatchBot\Domain\CharityRepository;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;
use Slim\Psr7\Request;
use Slim\Psr7\Uri;

class PullCharityUpdatedBasedOnSfHookTest extends \MatchBot\IntegrationTests\IntegrationTest
{
    public function testItPullsCharityUpdateAfterSalesforceSendsHook(): void
    {
        // arrange
        $em = $this->getService(EntityManager::class);

        $charity = TestCase::someCharity();
        $sfId = $charity->getSalesforceId();
        \assert(is_string($sfId));

        $em->persist($charity);
        $em->flush();

        // act
        $body = 'body is ignored';

        $salesforceSecretKey = getenv('SALESFORCE_SECRET_KEY');
        \assert(is_string($salesforceSecretKey));

        $response = $this->getApp()->handle(TestCase::createRequest(
            method: 'POST',
            path: '/hooks/charities/' . $sfId . '/update-required',
            bodyString: $body,
            headers: ['x-send-verify-hash' => hash_hmac('sha256', $body, $salesforceSecretKey)]
        ));

        $this->assertSame(200, $response->getStatusCode());

        // to-do: Invoke `matchbot:update-campaigns` command and make a mocked SF give the new charity name.

        // assert
        $charity = $this->getService(CharityRepository::class)->findOneBySfIDOrThrow(Salesforce18Id::of($sfId));
        $this->assertSame("New Charity Name", $charity->getName());
    }
}
