<?php

declare(strict_types=1);

namespace MatchBot\Tests;

use DI\ContainerBuilder;
use Exception;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\Charity;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Redis;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\Headers;
use Slim\Psr7\Request as SlimRequest;
use Slim\Psr7\Uri;

class TestCase extends PHPUnitTestCase
{
    use ProphecyTrait;

    /**
     * @var array<0|1, ?App> array of app instances with and without real redis. Each one may be
     *                       initialised up to once per test.
     */
    private array $appInstance = [0 => null, 1 => null];

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
    protected function createRequest(
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



    protected function getMinimalCampaign(): Campaign
    {
        return new Campaign(\MatchBot\Tests\TestCase::someCharity());
    }

    /**
     * Returns some random charity - use if you don't care about the details or will replace them with setters later.
     * Introduced to replace many old calls to instantiate Charity with zero arguments.
     */
    public static function someCharity(): Charity
    {
        return new Charity(
            salesforceId: '12CharityId_' .  self::randomHex(3),
            charityName: "Charity Name",
            stripeAccountId: "stripe-account-id-" . self::randomHex(),
            hmrcReferenceNumber: 'H' . self::randomHex(3),
            giftAidOnboardingStatus: 'Onboarded',
            regulator: 'CCEW',
            regulatorNumber: 'Reg-no',
            time: new \DateTime('2023-10-06T18:51:27'),
        );
    }

    /**
     * @param positive-int $num_bytes
     */
    private static function randomHex(int $num_bytes=8): string
    {
        return bin2hex(random_bytes($num_bytes));
    }
}
