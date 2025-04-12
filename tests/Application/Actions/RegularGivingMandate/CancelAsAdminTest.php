<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\RegularGivingMandate;

use DI\Container;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\FundRepository;
use MatchBot\Domain\MandateCancellationType;
use MatchBot\Domain\Money;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\RegularGivingMandateRepository;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;
use Ramsey\Uuid\Uuid;
use Slim\App;
use Slim\CallableResolver;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Response;
use Slim\Routing\Route;

class CancelAsAdminTest extends TestCase
{
    public function testSuccess(): void
    {
        $mandate = $this->getTestMandate();
        $mandateUuidString = $mandate->getUuid()->toString();

        $route = $this->getRouteWithMandateId($mandateUuidString);
        $request = self::createRequest('POST', "/v1/regular-giving/mandate/$mandateUuidString/cancel")
            ->withHeader('x-send-verify-hash', $this->getSalesforceAuthValue(''));

        $app = $this->getAppInstance();
        $this->mockRepositories($app, $mandate);

        $response = $app->handle($request->withAttribute('route', $route));

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testAlreadyCancelled(): void
    {
        $mandate = $this->getTestMandate();
        $mandate->cancel(
            reason: '',
            at: new \DateTimeImmutable(),
            type: MandateCancellationType::DonorRequestedCancellation
        );
        $mandateUuidString = $mandate->getUuid()->toString();

        $route = $this->getRouteWithMandateId($mandateUuidString);
        $request = self::createRequest('POST', "/v1/regular-giving/mandate/$mandateUuidString/cancel")
            ->withHeader('x-send-verify-hash', $this->getSalesforceAuthValue(''));

        $app = $this->getAppInstance();
        $this->mockRepositories($app, $mandate);

        $response = $app->handle($request->withAttribute('route', $route));

        $this->assertEquals(400, $response->getStatusCode());

        $payload = (string) $response->getBody();
        $expectedPayload = new ActionPayload(400, ['error' => [
            'type' => 'BAD_REQUEST',
            'description' => 'Mandate has existing non-cancelable status Cancelled',
        ]]);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($expectedSerialised, $payload);
    }

    private function getTestMandate(): RegularGivingMandate
    {
        $campaign = TestCase::someCampaign(
            sfId: Salesforce18Id::ofCampaign('campaignId12345678')
        );

        $mandate = new RegularGivingMandate(
            donorId: PersonId::of(Uuid::uuid4()->toString()),
            donationAmount: Money::fromPoundsGBP(20),
            campaignId: Salesforce18Id::ofCampaign($campaign->getSalesforceId()),
            charityId: Salesforce18Id::ofCharity($campaign->getCharity()->getSalesforceId()),
            giftAid: false,
            dayOfMonth: DayOfMonth::of(2),
        );
        $mandate->setId(1);

        return $mandate;
    }

    private function getSalesforceAuthValue(string $body): string
    {
        $salesforceSecretKey = getenv('SALESFORCE_SECRET_KEY');
        \assert(is_string($salesforceSecretKey));

        return hash_hmac('sha256', $body, $salesforceSecretKey);
    }

    private function getRouteWithMandateId(string $mandateUuidString): Route
    {
        $route = new Route(
            ['POST'],
            '',
            static function () {
                return new Response(200);
            },
            new ResponseFactory(),
            new CallableResolver()
        );
        $route->setArgument('mandateId', $mandateUuidString);

        return $route;
    }

    private function getMockDonationRepository(RegularGivingMandate $mandate): DonationRepository
    {
        $prophecy = $this->prophesize(DonationRepository::class);
        $prophecy->findPendingAndPreAuthedForMandate($mandate->getUuid())
            ->willReturn([]);

        return $prophecy->reveal();
    }

    private function getMockMandateRepository(RegularGivingMandate $mandate): RegularGivingMandateRepository
    {
        $prophecy = $this->prophesize(RegularGivingMandateRepository::class);
        $prophecy->findOneByUuid($mandate->getUuid())
            ->willReturn($mandate);

        return $prophecy->reveal();
    }

    private function mockRepositories(App $app, RegularGivingMandate $mandate): void
    {
        $container = $app->getContainer();
        \assert($container instanceof Container);

        $container->set(CampaignRepository::class, $this->createStub(CampaignRepository::class));
        $container->set(DonationRepository::class, $this->getMockDonationRepository($mandate));
        $container->set(RegularGivingMandateRepository::class, $this->getMockMandateRepository($mandate));
        $container->set(DonorAccountRepository::class, $this->createStub(DonorAccountRepository::class));
        $container->set(FundRepository::class, $this->createStub(FundRepository::class));
    }
}
