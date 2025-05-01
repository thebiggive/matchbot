<?php

namespace MatchBot\IntegrationTests;

use ArrayAccess;
use DI\Container;
use GuzzleHttp\Psr7\ServerRequest;
use Los\RateLimit\RateLimitMiddleware;
use MatchBot\Application\Assertion;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Application\Settings;
use MatchBot\Client\Mandate as MandateSFClient;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\DoctrineDonationRepository;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\FundType;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestData;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Random\Randomizer;
use Slim\App;
use Slim\Factory\AppFactory;
use Stripe\CustomerSession;
use Stripe\PaymentIntent;
use Stripe\Service\CustomerSessionService;
use Stripe\StripeClient;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * @psalm-import-type ApiClient from Settings
 */
abstract class IntegrationTest extends TestCase
{
    use ProphecyTrait;

    public static ?ContainerInterface $integrationTestContainer = null;
    public static ?App $app = null;

    public function setUp(): void
    {
        parent::setUp();

        $noOpMiddleware = new class implements MiddlewareInterface {
            public function process(
                ServerRequestInterface $request,
                RequestHandlerInterface $handler,
            ): ResponseInterface {
                return $handler->handle($request);
            }
        };

        $container = require __DIR__ . '/../bootstrap.php';
        IntegrationTest::setContainer($container);
        $container->set(RateLimitMiddleware::class, $noOpMiddleware);
        $container->set(\Psr\Log\LoggerInterface::class, new \Psr\Log\NullLogger());

        $settings = $container->get(Settings::class);
        $settings = $settings->withApiClient($this->fakeApiClientSettingsThatAlwaysThrow());
        $container->set('settings', $settings);

        $container->set(
            'donation-creation-rate-limiter-factory',
            new RateLimiterFactory(['id' => 'test', 'policy' => 'no_limit'], new InMemoryStorage())
        );


        AppFactory::setContainer($container);
        $app = AppFactory::create();

        $routes = require __DIR__ . '/../app/routes.php';
        $routes($app);

        $prophecy = $this->prophesize(MandateSFClient::class);
        $prophecy->createOrUpdate(Argument::any())->will(self::someSalesForce18Id(...));

        $this->getContainer()->set(MandateSFClient::class, $prophecy->reveal());

        self::setApp($app);
    }

    public static function someSalesForce18Id(): Salesforce18Id
    {
        return Salesforce18Id::of(self::randomString());
    }

    /**
     * @return Salesforce18Id<Campaign>
     */
    public static function someSalesForce18CampaignId(): Salesforce18Id
    {
        return Salesforce18Id::ofCampaign(self::randomString());
    }

    public static function setContainer(ContainerInterface $container): void
    {
        self::$integrationTestContainer = $container;
    }

    public static function setApp(App $app): void
    {
        self::$app = $app;
    }

    /**
     * @return ApiClient
     */
    private function fakeApiClientSettingsThatAlwaysThrow(): array
    {
        /** @var ApiClient $client */
        $client = [
            'global' => new /** @implements ArrayAccess<string, never> */ class implements ArrayAccess {
                public function offsetExists(mixed $offset): bool
                {
                    return true;
                }

                public function offsetGet(mixed $offset): never
                {
                    throw new \Exception("Do not use real API client in tests");
                }

                public function offsetSet(mixed $offset, mixed $value): never
                {
                    throw new \Exception("Do not use real API client in tests");
                }

                public function offsetUnset(mixed $offset): never
                {
                    throw new \Exception("Do not use real API client in tests");
                }
            },
            'mailer' => [
                'baseUri' => 'dummy-mailer-base-uri',
            ],
            'salesforce' => [
                'baseUri' => 'dummy-salesforce-base-uri',
            ],
        ];

        return $client;
    }

    protected function getContainer(): Container
    {
        if (self::$integrationTestContainer === null) {
            throw new \Exception("Test container not set");
        }

        \assert(self::$integrationTestContainer instanceof \DI\Container);

        return self::$integrationTestContainer;
    }

    /**
     * @param class-string $name
     */
    protected function setInContainer(string $name, mixed $value): void
    {
        $container = $this->getContainer();

        $container->set($name, $value);
    }

    public function db(): \Doctrine\DBAL\Connection
    {
        return $this->getService(\Doctrine\ORM\EntityManagerInterface::class)->getConnection();
    }

    public function clearPreviousCampaignsCharitiesAndRelated(): void
    {
        $this->db()->executeStatement('DELETE FROM FundingWithdrawal');
        $this->db()->executeStatement('DELETE FROM Donation');
        $this->db()->executeStatement('DELETE FROM Campaign_CampaignFunding');
        $this->db()->executeStatement('DELETE FROM Campaign');
        $this->db()->executeStatement('DELETE FROM Charity');
    }

    /**
     * @return string Campaign ID
     */
    public function setupNewCampaign(): string
    {
        $campaignId = $this->randomString();
        $paymentIntentId = $this->randomString();

        $this->addFundedCampaignAndCharityToDB($campaignId);

        $stripePaymentIntent = new PaymentIntent($paymentIntentId);
        $stripePaymentIntent->client_secret = 'any string, doesnt affect test';
        $stripePaymentIntentsProphecy = $this->setUpFakeStripeClient();

        $stripePaymentIntentsProphecy->create(Argument::type('array'))
            ->willReturn($stripePaymentIntent);

        $container = $this->getContainer();

        $donationClientProphecy = $this->prophesize(\MatchBot\Client\Donation::class);
        $donationClientProphecy->createOrUpdate(Argument::type(DonationUpserted::class))->willReturn(
            Salesforce18Id::of($this->randomString())
        );

        $container->set(\MatchBot\Client\Donation::class, $donationClientProphecy->reveal());

        $donationRepo = $container->get(DonationRepository::class);
        Assertion::isInstanceOf($donationRepo, DoctrineDonationRepository::class);
        $donationRepo->setClient($donationClientProphecy->reveal());
        return $campaignId;
    }

    /**
     * @param string $campaignSfId
     * @return array{charityId: int, campaignId: int}
     * @throws \Doctrine\DBAL\Exception
     */
    public function addCampaignAndCharityToDB(
        string $campaignSfId,
        bool $campaignOpen = true,
        string $charitySfId = null,
        string $charityName = 'Some Charity',
        bool $isRegularGiving = false
    ): array {
        $charityId = random_int(1000, 100000);
        $charitySfId ??= $this->randomString();
        $charityStripeId = $this->randomString();
        $isRegularGivingInt = $isRegularGiving ? 1 : 0;
        $db = $this->db();

        $nyd = '2023-01-01'; // specific date doesn't matter.
        $closeDate = $campaignOpen ? '2093-01-01' : '2023-01-02';

        $db->executeStatement(<<<EOF
            INSERT INTO Charity (id, name, salesforceId, salesforceLastPull, createdAt, updatedAt, stripeAccountId,
                     hmrcReferenceNumber, tbgClaimingGiftAid, tbgApprovedToClaimGiftAid, regulator, regulatorNumber, 
                                 salesforceData)
            VALUES ($charityId, '$charityName', '$charitySfId', '$nyd', '$nyd', '$nyd', '$charityStripeId',
                    null, 0, 0, null, null, '{}')
            EOF
        );

        $charityId = (int)$db->lastInsertId();

        $matched =  1;

        $db->executeStatement(<<<EOF
            INSERT INTO Campaign (charity_id, name, startDate, endDate, isMatched, salesforceId, salesforceLastPull,
                                  createdAt, updatedAt, currencyCode, isRegularGiving, salesforceData)
            VALUES ('$charityId', 'some charity', '$nyd', '$closeDate', '$matched', '$campaignSfId', '$nyd',
                    '$nyd', '$nyd', 'GBP',  '$isRegularGivingInt', '{}')
            EOF
        );

        $campaignId = (int)$db->lastInsertId();

        return compact('charityId', 'campaignId');
    }

    /**
     * @param string $campaignSfId
     * @return array{charityId: int, campaignId: int, fundId: int, campaignFundingId: int}
     * @throws \Doctrine\DBAL\Exception
     * @psalm-suppress MoreSpecificReturnType
     * @psalm-suppress LessSpecificReturnStatement
     */
    public function addFundedCampaignAndCharityToDB(
        string $campaignSfId,
        int $fundWithAmountInPounds = 100_000,
        bool $isRegularGiving = false,
        FundType $fundType = FundType::Pledge,
    ): array {
        ['charityId' => $charityId, 'campaignId' => $campaignId] = $this->addCampaignAndCharityToDB(
            campaignSfId: $campaignSfId,
            campaignOpen: true,
            isRegularGiving: $isRegularGiving
        );
        ['fundId' => $fundId, 'campaignFundingId' => $campaignFundingId] =
            $this->addFunding($campaignId, $fundWithAmountInPounds, $fundType);

        $compacted = compact('charityId', 'campaignId', 'fundId', 'campaignFundingId');
        Assertion::allInteger($compacted);

        return $compacted;
    }

    /**
     * @param FundType $fundType
     * @return array{fundId: int, campaignFundingId: int}
     * @psalm-suppress MoreSpecificReturnType
     * @psalm-suppress LessSpecificReturnStatement
     */
    public function addFunding(
        int $campaignId,
        int $amountInPounds,
        FundType $fundType,
    ): array {
        $db = $this->db();
        $fundSfID = $this->randomString();
        $nyd = '2023-01-01'; // specific date doesn't matter.

        $db->executeStatement(<<<SQL
            INSERT INTO Fund (name, salesforceId, salesforceLastPull, createdAt, updatedAt, fundType,
                              currencyCode, allocationOrder) VALUES 
                ('Some test fund', '$fundSfID', '$nyd', '$nyd', '$nyd', '{$fundType->value}',
                 'GBP', {$fundType->allocationOrder()})
        SQL
        );

        $fundId = (int)$db->lastInsertId();

        $db->executeStatement(<<<SQL
            INSERT INTO CampaignFunding (fund_id, amount, amountAvailable, createdAt, updatedAt,
                                         currencyCode) VALUES 
                    ($fundId, $amountInPounds, $amountInPounds, '$nyd', '$nyd',
                     'GBP')
        SQL
        );

        $campaignFundingId = (int)$db->lastInsertId();

        $db->executeStatement(<<<SQL
         INSERT INTO Campaign_CampaignFunding (campaignfunding_id, campaign_id)
         VALUES ($campaignFundingId, $campaignId);
        SQL
        );

        return compact('fundId', 'campaignFundingId');
    }

    /**
     * @return ObjectProphecy<\Stripe\Service\PaymentIntentService>
     */
    public function setUpFakeStripeClient(): ObjectProphecy
    {
        $stripePaymentIntentsProphecy = $this->prophesize(\Stripe\Service\PaymentIntentService::class);
        $stripeCustomerSessions = $this->prophesize(CustomerSessionService::class);
        $customerSession = new CustomerSession();
        $customerSession->client_secret = 'client_secret';
        $stripeCustomerSessions->create(Argument::any())
            ->willReturn($customerSession);

        $fakeStripeClient = $this->fakeStripeClient(
            $this->prophesize(\Stripe\Service\PaymentMethodService::class),
            $this->prophesize(\Stripe\Service\CustomerService::class),
            $stripePaymentIntentsProphecy,
            $stripeCustomerSessions,
        );

        $container = $this->getContainer();
        $container->set(StripeClient::class, $fakeStripeClient);
        return $stripePaymentIntentsProphecy;
    }

    public static function randomString(): string
    {
        return (new Randomizer())->getBytesFromString('abcdef01234567890', 18);
    }

    protected function getApp(): App
    {
        if (self::$app === null) {
            throw new \Exception("Test app not set");
        }
        return self::$app;
    }

    /**
     * @template T
     * @param class-string<T> $name
     * @return T
     */
    public function getService(string $name): mixed
    {
        $service = $this->getContainer()->get($name);
        $this->assertInstanceOf($name, $service);

        return $service;
    }

    /**
     * Used in the past, maybe useful again, so
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getServiceByName(string $name): mixed
    {
        return $this->getContainer()->get($name);
    }

    /**
     * @psalm-suppress UndefinedPropertyAssignment - StripeClient does declare the properties via docblock, not sure
     * Psalm doesn't see them as defined.
     */
    public function fakeStripeClient(
        ObjectProphecy $stripePaymentMethodServiceProphecy,
        ObjectProphecy $stripeCustomerServiceProphecy,
        ObjectProphecy $stripePaymentIntents,
        ObjectProphecy $stripeCustomerSessions
    ): StripeClient {
        $fakeStripeClient = $this->createStub(StripeClient::class);

        // Suppressing deprecation warnings with `@` for creation of dynamic properties. Will crash in PHP 9, we can
        // deal with it then if the code is still there.
        @$fakeStripeClient->paymentMethods = $stripePaymentMethodServiceProphecy->reveal();
        @$fakeStripeClient->customers = $stripeCustomerServiceProphecy->reveal();
        @$fakeStripeClient->paymentIntents = $stripePaymentIntents->reveal();
        @$fakeStripeClient->customerSessions = $stripeCustomerSessions->reveal();

        return $fakeStripeClient;
    }

    protected function createDonation(
        int $tipAmount = 0,
        bool $withPremadeCampaign = true,
        ?string $campaignSfID = null,
        int $amountInPounds = 100,
    ): ResponseInterface {
        $campaignId = $campaignSfID ?? $this->randomString();
        $paymentIntentId = $this->randomString();

        if ($withPremadeCampaign) {
            $this->addFundedCampaignAndCharityToDB($campaignId);
        } // else application will attempt to pull campaign and charity from SF.

        $stripePaymentIntent = new PaymentIntent($paymentIntentId);
        $stripePaymentIntent->client_secret = 'any string, doesnt affect test';
        $stripePaymentIntentsProphecy = $this->setUpFakeStripeClient();

        $stripePaymentIntentsProphecy->create(Argument::type('array'))
            ->willReturn($stripePaymentIntent);

        $container = $this->getContainer();

        $donationClientProphecy = $this->prophesize(\MatchBot\Client\Donation::class);
        $donationClientProphecy->createOrUpdate(Argument::type(DonationUpserted::class))->willReturn(
            Salesforce18Id::of($this->randomString())
        );

        $container->set(\MatchBot\Client\Donation::class, $donationClientProphecy->reveal());

        $donationRepo = $container->get(DonationRepository::class);
        Assertion::isInstanceOf($donationRepo, DoctrineDonationRepository::class);
        $donationRepo->setClient($donationClientProphecy->reveal());

        return $this->getApp()->handle(
            new ServerRequest(
                'POST',
                TestData\Identity::TEST_PERSON_NEW_DONATION_ENDPOINT,
                headers: [
                    'X-Tbg-Auth' => TestData\Identity::getTestIdentityTokenComplete(),
                ],
                // The Symfony Serializer will throw an exception if the JSON document doesn't include all the required
                // constructor params of DonationCreate
                body: <<<EOF
                {
                    "currencyCode": "GBP",
                    "donationAmount": "{$amountInPounds}",
                    "projectId": "$campaignId",
                    "psp": "stripe",
                    "pspCustomerId": "cus_aaaaaaaaaaaa11",
                    "tipAmount": $tipAmount
                }
            EOF,
                serverParams: ['REMOTE_ADDR' => '127.0.0.1']
            )
        );
    }
}
