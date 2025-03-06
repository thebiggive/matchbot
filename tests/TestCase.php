<?php

declare(strict_types=1);

namespace MatchBot\Tests;

use DI\Container;
use DI\ContainerBuilder;
use Exception;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\Charity;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\Application\Actions\Donations\CreateTest;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Random\Randomizer;
use Redis;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Headers;
use Slim\Psr7\Request as SlimRequest;
use Slim\Psr7\Uri;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class TestCase extends PHPUnitTestCase
{
    use ProphecyTrait;

    /**
     * @var array<0|1, ?App> array of app instances with and without real redis. Each one may be
     *                       initialised up to once per test.
     */
    private array $appInstance = [0 => null, 1 => null];

    public function getContainer(): ContainerInterface
    {
        $container = $this->getAppInstance()->getContainer();
        \assert($container !== null);

        return $container;
    }

    /**
     * @return App
     * @throws Exception
     */
    protected function getAppInstance(bool $withRealRedis = false): App
    {
        $memoizedInstance = $this->appInstance[(int)$withRealRedis];
        if ($memoizedInstance) {
            return $memoizedInstance;
        }

        // Instantiate PHP-DI ContainerBuilder
        $containerBuilder = new ContainerBuilder();

        // Container intentionally not compiled for tests.

        // Set up settings
        $settings = require __DIR__ . '/../app/settings.php';
        $settings($containerBuilder);

        // Set up dependencies
        $dependencies = require __DIR__ . '/../app/dependencies.php';
        $dependencies($containerBuilder);

        // Set up repositories
        $repositories = require __DIR__ . '/../app/repositories.php';
        $repositories($containerBuilder);

        // Build PHP-DI Container instance
        $container = $containerBuilder->build();

        $container->set(
            'donation-creation-rate-limiter-factory',
            new RateLimiterFactory(['id' => 'test', 'policy' => 'no_limit'], new InMemoryStorage())
        );

        if (!$withRealRedis) {
            // For unit tests, we need to stub out Redis so that rate limiting middleware doesn't
            // crash trying to actually connect to REDIS_HOST "dummy-redis-hostname".
            $redisProphecy = $this->prophesize(Redis::class);
            $redisProphecy->isConnected()->willReturn(true);
            $redisProphecy->mget(Argument::type('array'))->willReturn([]);
            // symfony/cache Redis adapter apparently does something around prepping value-setting
            // through a fancy pipeline() and calls this.
            $redisProphecy->multi(Argument::any())->willReturn(true);
            $redisProphecy
                ->setex(Argument::type('string'), 3600, Argument::type('string'))
                ->willReturn(true);
            $redisProphecy->exec()->willReturn([]); // Commits the multi() operation.
            $container->set(Redis::class, $redisProphecy->reveal());
        }

        // By default, tests don't get a real logger.
        $container->set(LoggerInterface::class, new NullLogger());

        $container->set(ClockInterface::class, new class implements ClockInterface
        {
            #[\Override] public function sleep(float|int $seconds): never
            {
                throw new \Exception("Please provide fake clock for your test");
            }
            #[\Override] public function now(): \DateTimeImmutable
            {
                // This always throws but I'm using an if condition to hide that from PhpStorm. Otherwise
                // PhpStorm infers the return type as `never`, finds usages of the Interface in our prod code
                // and somehow assumes they're calling this anon class and complains about dead code.
                if (time() !== 1) {
                    throw new \Exception("Please provide fake clock for your test");
                }

                return new \DateTimeImmutable();
            }
            #[\Override] public function withTimeZone(\DateTimeZone|string $timezone): never
            {
                throw new \Exception("Please provide fake clock for your test");
            }
        });

        // Instantiate the app
        AppFactory::setContainer($container);
        $app = AppFactory::create();

        // Register routes
        $routes = require __DIR__ . '/../app/routes.php';
        $routes($app);

        $app->addRoutingMiddleware();
        $this->appInstance[(int) $withRealRedis] = $app;

        return $app;
    }

    /**
     * @param string $method
     * @param string $path
     * @param string $bodyString
     * @param array $headers
     * @param array $serverParams
     * @param array $cookies
     * @return Request
     */
    public static function createRequest(
        string $method,
        string $path,
        string $bodyString = '',
        array $headers = [
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X-Forwarded-For' => '1.2.3.4', // Simulate ALB in unit tests by default.
        ],
        array $serverParams = [],
        array $cookies = []
    ): Request {
        $uri = new Uri('', '', 80, $path);
        $handle = fopen('php://temp', 'w+');
        \assert($handle !== false);

        if ($bodyString === '') {
            $stream = (new StreamFactory())->createStreamFromResource($handle);
        } else {
            $stream = (new StreamFactory())->createStream($bodyString);
        }

        $h = new Headers();
        foreach ($headers as $name => $value) {
            $h->addHeader($name, $value);
        }

        return new SlimRequest($method, $uri, $h, $cookies, $serverParams, $stream);
    }

    public static function getMinimalCampaign(): Campaign
    {
        $charity = self::someCharity();
        $charity->setTbgClaimingGiftAid(false);
        $campaign = TestCase::someCampaign(
            charity: $charity
        );
        $campaign->setIsMatched(false);

        return $campaign;
    }

    /**
     * Returns some random charity - use if you don't care about the details or will replace them with setters later.
     * Introduced to replace many old calls to instantiate Charity with zero arguments.
     */
    public static function someCharity(
        ?string $stripeAccountId = null,
        ?Salesforce18Id $salesforceId = null,
        string $name = 'Charity Name'
    ): Charity {
        return new Charity(
            salesforceId: $salesforceId?->value ?? ('123CharityId' .  self::randomHex(3)),
            charityName: $name,
            stripeAccountId: $stripeAccountId ?? "stripe-account-id-" . self::randomHex(),
            hmrcReferenceNumber: 'H' . self::randomHex(3),
            giftAidOnboardingStatus: 'Onboarded',
            regulator: 'CCEW',
            regulatorNumber: 'Reg-no',
            time: new \DateTime('2023-10-06T18:51:27'),
        );
    }

    /**
     * @param ?Salesforce18Id<Campaign> $sfId
     */
    public static function someCampaign(
        ?string $stripeAccountId = null,
        ?Salesforce18Id $sfId = null,
        ?Charity $charity = null,
        bool $isRegularGiving = false,
        ?\DateTimeImmutable $regularGivingCollectionEnd = null,
        string $thankYouMessage = null,
    ): Campaign {
        $randomString = (new Randomizer())->getBytesFromString('abcdef', 7);
        $sfId ??= Salesforce18Id::ofCampaign('1CampaignId' . $randomString);

        return new Campaign(
            $sfId,
            $charity ?? self::someCharity(stripeAccountId: $stripeAccountId),
            startDate: new \DateTimeImmutable('2020-01-01'),
            endDate: new \DateTimeImmutable('3000-01-01'),
            isMatched: false,
            ready: true,
            status: 'status',
            name: 'someCampaign',
            currencyCode: 'GBP',
            isRegularGiving: $isRegularGiving,
            regularGivingCollectionEnd: $regularGivingCollectionEnd,
            thankYouMessage: $thankYouMessage,
        );
    }

    /**
     * @param numeric-string $amount
     * @param numeric-string $tipAmount
     */
    public static function someDonation(
        string $amount = '1',
        string $currencyCode = 'GBP',
        PaymentMethodType $paymentMethodType = PaymentMethodType::Card,
        bool $giftAid = false,
        ?RegularGivingMandate $regularGivingMandate = null,
        ?Campaign $campaign = null,
        string $tipAmount = '0',
        ?EmailAddress $emailAddress = null,
        ?DonorName $donorName = null,
    ): Donation {
        return new Donation(
            amount: $amount,
            currencyCode: $currencyCode,
            paymentMethodType: $paymentMethodType,
            campaign: $campaign ?? self::someCampaign(),
            charityComms: null,
            championComms: null,
            pspCustomerId: null,
            optInTbgEmail: null,
            donorName: $donorName,
            emailAddress: $emailAddress,
            countryCode: null,
            tipAmount: $tipAmount,
            mandate: $regularGivingMandate,
            mandateSequenceNumber: null,
            giftAid: $giftAid,
            tipGiftAid: null,
            homeAddress: null,
            homePostcode: null,
            billingPostcode: null,
        );
    }

    public static function someUpsertedMessage(): DonationUpserted
    {
        $donation = self::someDonation();
        $donation->setTransactionId('pi_1234');

        return DonationUpserted::fromDonation($donation);
    }

    /**
     * @param positive-int $num_bytes
     */
    private static function randomHex(int $num_bytes=8): string
    {
        return bin2hex(random_bytes($num_bytes));
    }

    public static function randomPersonId(): PersonId
    {
        return PersonId::of(Uuid::uuid4()->toString());
    }

    public function diContainer(): Container
    {
        $container = $this->getAppInstance()->getContainer();

        \assert($container instanceof Container);

        return $container;
    }
}
