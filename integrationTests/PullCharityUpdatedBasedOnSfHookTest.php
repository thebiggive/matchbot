<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManager;
use Laminas\Diactoros\ServerRequest;
use MatchBot\Application\Commands\Command;
use MatchBot\Application\Commands\LockingCommand;
use MatchBot\Application\Commands\UpdateCampaigns;
use MatchBot\Application\Commands\UpdateCharities;
use MatchBot\Client;
use MatchBot\Domain\Charity;
use MatchBot\Domain\CharityRepository;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\Application\Commands\AlwaysAvailableLockStore;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Request;
use Slim\Psr7\Uri;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

class PullCharityUpdatedBasedOnSfHookTest extends \MatchBot\IntegrationTests\IntegrationTest
{
    public function tearDown(): void
    {
        $this->getContainer()->set(Client\Fund::class, null);
        $this->getContainer()->set(Client\Campaign::class, null);
    }

    public function testItPullsCharityUpdateAfterSalesforceSendsHook(): void
    {
        // arrange
        $em = $this->getService(EntityManager::class);

        $campaign = TestCase::someCampaign();
        $charity = $campaign->getCharity();
        $sfId = $charity->getSalesforceId();
        \assert(is_string($sfId));

        $em->persist($campaign);
        $em->persist($charity);
        $em->flush();

        $campaignClientProphecy = $this->prophesize(Client\Campaign::class);

        $campaignClientProphecy->getById($campaign->getSalesforceId())->willReturn(
            $this->simulatedCampaignFromSFAPI(
                $sfId,
                'New Charity Name',
                $charity->getStripeAccountId() ?? throw new \Exception('Missing Stripe ID')
            )
        );

        $this->getContainer()->set(Client\Campaign::class, $campaignClientProphecy->reveal());

        // act
        $this->simulateRequestFromSFTo('/hooks/charities/' . $sfId . '/update-required');

        // assert
        $em->clear();

        $charity = $this->getService(CharityRepository::class)->findOneBySfIDOrThrow(Salesforce18Id::of($sfId));
        $this->assertSame('New Charity Name', $charity->getName());
    }

    private function simulatedCampaignFromSFAPI(string $sfId, string $newCharityName, string $stripeAccountId): array
    {
        return [
            'currencyCode' => 'GBP',
            'endDate' => '2020-01-01',
            'startDate' => '2020-01-01',
            'isMatched' => true,
            'title' => 'Campaign title not relavent',
            'charity' => [
                'id' => $sfId,
                'name' => $newCharityName,
                'stripeAccountId' => $stripeAccountId,
                'giftAidOnboardingStatus' => 'Onboarded',
                'hmrcReferenceNumber' => null,
                'regulatorRegion' => 'England and Wales',
                'regulatorNumber' => null,
            ]
        ];
    }

    private function simulateRequestFromSFTo(string $URI): void
    {
        $salesforceSecretKey = getenv('SALESFORCE_SECRET_KEY');
        \assert(is_string($salesforceSecretKey));

        $body = 'body is ignored';

        $this->getApp()->handle(TestCase::createRequest(
            method: 'POST',
            path: $URI,
            bodyString: $body,
            headers: ['x-send-verify-hash' => hash_hmac('sha256', $body, $salesforceSecretKey)]
        ));
    }
}
