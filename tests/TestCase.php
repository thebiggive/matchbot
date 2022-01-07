<?php

declare(strict_types=1);

namespace MatchBot\Tests;

use DI\ContainerBuilder;
use Exception;
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
     * @return App
     * @throws Exception
     */
    protected function getAppInstance(bool $withRealRedis = false): App
    {
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
            $redisProphecy->mget(['matchbot-cache:10d49f663215e991d10df22692f03e89'])->willReturn(null);
            // symfony/cache Redis adapter apparently does something around prepping value-setting
            // through a fancy pipeline() and calls this.
            $redisProphecy->multi(Argument::any())->willReturn();
            $redisProphecy
                ->setex('matchbot-cache:10d49f663215e991d10df22692f03e89', 3600, Argument::type('string'))
                ->willReturn(true);
            $redisProphecy->exec()->willReturn(); // Commits the multi() operation.
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
}
