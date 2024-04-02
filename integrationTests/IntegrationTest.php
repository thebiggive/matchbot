<?php

namespace MatchBot\IntegrationTests;

use ArrayAccess;
use DI\Container;
use GuzzleHttp\Psr7\ServerRequest;
use Los\RateLimit\RateLimitMiddleware;
use MatchBot\Application\Assertion;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\Fund;
use MatchBot\Domain\Pledge;
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
use Ramsey\Uuid\Uuid;
use Random\Randomizer;
use Slim\App;
use Slim\Factory\AppFactory;
use Stripe\PaymentIntent;
use Stripe\StripeClient;

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

        $settings = $container->get('settings');
        \assert(is_array($settings));
        $settings['apiClient'] = $this->fakeApiClientSettingsThatAlwaysThrow();
        $container->set('settings', $settings);


        AppFactory::setContainer($container);
        $app = AppFactory::create();

        $routes = require __DIR__ . '/../app/routes.php';
        $routes($app);

        self::setApp($app);
    }

    public static function setContainer(ContainerInterface $container): void
    {
        self::$integrationTestContainer = $container;
    }

    public static function setApp(App $app): void
    {
        self::$app = $app;
    }

    private function fakeApiClientSettingsThatAlwaysThrow(): array
    {
        return ['global' => new /** @implements ArrayAccess<string, never> */ class implements ArrayAccess {
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
        }];
    }

    /**
     * @return void
     * @throws \DI\DependencyException
     * @throws \DI\NotFoundException
     * @throws \MatchBot\Client\BadRequestException
     */
    public function setupFakeDonationClient(): void
    {
        $container = $this->getContainer();

        $donationClientProphecy = $this->prophesize(\MatchBot\Client\Donation::class);
        $donationClientProphecy->create(Argument::type(Donation::class))->willReturn($this->randomString());
        $donationClientProphecy->put(Argument::type(Donation::class))->willReturn(true);

        $container->set(\MatchBot\Client\Donation::class, $donationClientProphecy->reveal());

        $donationRepo = $container->get(DonationRepository::class);
        $donationRepo->setClient($donationClientProphecy->reveal());
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
        $donationClientProphecy->create(Argument::type(Donation::class))->willReturn($this->randomString());
        $donationClientProphecy->put(Argument::type(Donation::class))->willReturn(true);

        $container->set(\MatchBot\Client\Donation::class, $donationClientProphecy->reveal());

        $donationRepo = $container->get(DonationRepository::class);
        $donationRepo->setClient($donationClientProphecy->reveal());
        return $campaignId;
    }

    /**
     * @param string $campaignSfId
     * @return array{charityId: int, campaignId: int}
     * @throws \Doctrine\DBAL\Exception
     *
     * @psalm-suppress MoreSpecificReturnType
     * @psalm-suppress LessSpecificReturnStatement
     */
    public function addCampaignAndCharityToDB(string $campaignSfId, bool $campaignOpen): array
    {
        $charityId = random_int(1000, 100000);
        $charitySfID = $this->randomString();
        $charityStripeId = $this->randomString();

        $db = $this->db();

        $nyd = '2023-01-01'; // specific date doesn't matter.
        $closeDate = $campaignOpen ? '2093-01-01' : '2023-01-02';

        $db->executeStatement(<<<EOF
            INSERT INTO Charity (id, name, salesforceId, salesforceLastPull, createdAt, updatedAt, stripeAccountId,
                     hmrcReferenceNumber, tbgClaimingGiftAid, tbgApprovedToClaimGiftAid, regulator, regulatorNumber) 
            VALUES ($charityId, 'Some Charity', '$charitySfID', '$nyd', '$nyd', '$nyd', '$charityStripeId',
                    null, 0, 0, null, null)
            EOF
        );

        $charityId = (int)$db->lastInsertId();

        $matched =  1;

        $db->executeStatement(<<<EOF
            INSERT INTO Campaign (charity_id, name, startDate, endDate, isMatched, salesforceId, salesforceLastPull,
                                  createdAt, updatedAt, currencyCode, feePercentage) 
            VALUES ('$charityId', 'some charity', '$nyd', '$closeDate', '$matched', '$campaignSfId', '$nyd',
                    '$nyd', '$nyd', 'GBP', 0)
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
    public function addFundedCampaignAndCharityToDB(string $campaignSfId, int $fundWithAmountInPounds = 100_000): array
    {
        ['charityId' => $charityId, 'campaignId' => $campaignId] = $this->addCampaignAndCharityToDB(
            campaignSfId: $campaignSfId,
            campaignOpen: true,
        );
        ['fundId' => $fundId, 'campaignFundingId' => $campaignFundingId] =
            $this->addFunding($campaignId, $fundWithAmountInPounds, 1, Pledge::DISCRIMINATOR_VALUE);

        $compacted = compact('charityId', 'campaignId', 'fundId', 'campaignFundingId');
        Assertion::allInteger($compacted);

        return $compacted;
    }

    /**
     * @param 'championFund'|'pledge'|'unknownFund' $fundType
     * @return array{fundId: int, campaignFundingId: int}
     * @psalm-suppress MoreSpecificReturnType
     * @psalm-suppress LessSpecificReturnStatement
     */
    public function addFunding(
        int $campaignId,
        int $amountInPounds,
        int $allocationOrder,
        string $fundType = Fund::DISCRIMINATOR_VALUE,
    ): array {
        $db = $this->db();
        $fundSfID = $this->randomString();
        $nyd = '2023-01-01'; // specific date doesn't matter.

        $db->executeStatement(<<<SQL
            INSERT INTO Fund (amount, name, salesforceId, salesforceLastPull, createdAt, updatedAt, fundType,
                              currencyCode) VALUES 
                (100000, 'Some test fund', '$fundSfID', '$nyd', '$nyd', '$nyd', '$fundType',
                 'GBP')
        SQL
        );

        $fundId = (int)$db->lastInsertId();

        $db->executeStatement(<<<SQL
            INSERT INTO CampaignFunding (fund_id, amount, amountAvailable, allocationOrder, createdAt, updatedAt,
                                         currencyCode) VALUES 
                    ($fundId, $amountInPounds, $amountInPounds, $allocationOrder, '$nyd', '$nyd',
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

        $fakeStripeClient = $this->fakeStripeClient(
            $this->prophesize(\Stripe\Service\PaymentMethodService::class),
            $this->prophesize(\Stripe\Service\CustomerService::class),
            $stripePaymentIntentsProphecy,
        );

        $container = $this->getContainer();
        $container->set(StripeClient::class, $fakeStripeClient);
        return $stripePaymentIntentsProphecy;
    }

    public function randomString(): string
    {
        $randomString = (new Randomizer())->getBytesFromString('abcdef01234567890', 18);

        \assert(is_string($randomString)); // not sure why Psalm said it was mixed.

        return $randomString;
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
    ): StripeClient {
        $fakeStripeClient = $this->createStub(StripeClient::class);

        // Suppressing deprecation warnings with `@` for creation of dynamic properties. Will crash in PHP 9, we can
        // deal with it then if the code is still there.
        @$fakeStripeClient->paymentMethods = $stripePaymentMethodServiceProphecy->reveal();
        @$fakeStripeClient->customers = $stripeCustomerServiceProphecy->reveal();
        @$fakeStripeClient->paymentIntents = $stripePaymentIntents->reveal();

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
        $donationClientProphecy->create(Argument::type(Donation::class))->willReturn($this->randomString());
        $donationClientProphecy->put(Argument::type(Donation::class))->willReturn(true);

        $container->set(\MatchBot\Client\Donation::class, $donationClientProphecy->reveal());

        $donationRepo = $container->get(DonationRepository::class);
        $donationRepo->setClient($donationClientProphecy->reveal());

        return $this->getApp()->handle(
            new ServerRequest(
                'POST',
                TestData\Identity::getTestPersonNewDonationEndpoint(),
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
