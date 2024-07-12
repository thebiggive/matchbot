<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManager;
use Laminas\Diactoros\ServerRequest;
use MatchBot\Application\Commands\UpdateCampaigns;
use MatchBot\Client;
use MatchBot\Domain\Charity;
use MatchBot\Domain\CharityRepository;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\Application\Commands\AlwaysAvailableLockStore;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Psr\Log\NullLogger;
use Slim\Psr7\Request;
use Slim\Psr7\Uri;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

class PullCharityUpdatedBasedOnSfHookTest extends \MatchBot\IntegrationTests\IntegrationTest
{
    public function tearDown(): void
    {
        $container = $this->getContainer();
        $container->set(Client\Fund::class, null);
        $container->set(Client\Campaign::class, null);
    }

    /**
     * @psalm-suppress UnevaluatedCode
     */
    public function testItPullsCharityUpdateAfterSalesforceSendsHook(): void
    {
        $this->markTestSkipped();
        // arrange
        $em = $this->getService(EntityManager::class);

        $campaign = TestCase::someCampaign();
        $charity = $campaign->getCharity();
        $sfId = $charity->getSalesforceId();
        \assert(is_string($sfId));

        $em->persist($campaign);
        $em->persist($charity);
        $em->flush();

        $fundClientRepository = $this->prophesize(Client\Fund::class);
        $this->getContainer()->set(Client\Fund::class, $fundClientRepository->reveal());
        $fundClientRepository->getForCampaign(Argument::type('string'))->willReturn([]);

        $campaignClientProphecy = $this->prophesize(Client\Campaign::class);

        $campaignClientProphecy->getById(Argument::any())->willReturn([
            'currencyCode' => 'GBP',
            'endDate' => '2020-01-01',
            'startDate' => '2020-01-01',
            'feePercentage' => null,
            'isMatched' => true,
            'title' => 'Campaign title not relavent',
            'charity' => [
                'id' => $sfId,
                'name' => 'New Charity Name',
                'stripeAccountId' => $charity->getStripeAccountId(),
                'giftAidOnboardingStatus' => 'Onboarded',
                'hmrcReferenceNumber' => null,
                'regulatorRegion' => 'England and Wales',
                'regulatorNumber' => null,
            ]
        ]);

        $this->getContainer()->set(Client\Campaign::class, $campaignClientProphecy->reveal());
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
        $service = $this->getService(UpdateCampaigns::class);

        $service->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $service->setLogger(new NullLogger());

        $commandTester = new CommandTester($service);
        $commandTester->execute([]);

        // to-do: Invoke `matchbot:update-campaigns` command and make a mocked SF give the new charity name.

        $em->clear();

        // assert
        $charity = $this->getService(CharityRepository::class)->findOneBySfIDOrThrow(Salesforce18Id::of($sfId));
        $this->assertSame("New Charity Name", $charity->getName());
    }
}
