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
use ReCaptcha\ReCaptcha;
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

        $recaptchaProphecy = $this->prophesize(ReCaptcha::class);
        $recaptchaProphecy->verify('good response', '1.2.3.4')
            ->willReturn(new \ReCaptcha\Response(true));
        $recaptchaProphecy->verify('bad response', '1.2.3.4')
            ->willReturn(new \ReCaptcha\Response(false));
        // Blank is mocked succeeding so that the deserialise error unit test behaves
        // as it did before we had captcha verification.
        $recaptchaProphecy->verify('', '1.2.3.4')
            ->willReturn(new \ReCaptcha\Response(true));
        $container->set(ReCaptcha::class, $recaptchaProphecy->reveal());

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

    /**
     * @see TestCase::getTestIdentityTokenIncomplete()
     */
    protected function getTestIdentityTokenComplete(): string
    {
        // As below but `"complete": true`.
        return 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.' .
         'eyJpc3MiOiJodHRwczovL3VuaXQtdGVzdC1mYWtlLWlkLXN1Yi50aGViaWdnaXZldGVzdC5vcmcudWsiLCJpYXQiOjE2NjM5NDQ4ODksImV' .
         '4cCI6MjUyNDYwODAwMCwic3ViIjp7InBlcnNvbl9pZCI6IjEyMzQ1Njc4LTEyMzQtMTIzNC0xMjM0LTEyMzQ1Njc4OTBhYiIsImNvbXBsZX' .
         'RlIjp0cnVlLCJwc3BfaWQiOiJjdXNfYWFhYWFhYWFhYWFhMTEifX0.9zk7DUdvC9BWuRhXo2p7r12ZiTuREV7v9zsY97p_fyA';
    }

    protected function getTestIdentityTokenIncomplete(): string
    {
        // One-time, artifically long token generated and hard-coded here so that we don't
        // need live code just for MatchBot to issue ID tokens only for unit tests.
        // Token is for Stripe Customer cus_aaaaaaaaaaaa11.
        //
        // Base 64 decoded body part:
        // {
        //  "iss":"https://unit-test-fake-id-sub.thebiggivetest.org.uk",
        //  "iat":1663436154,
        //  "exp":2524608000,
        //  "sub": {
        //      "person_id":"12345678-1234-1234-1234-1234567890ab",
        //      "complete":false,
        //      "psp_id":"cus_aaaaaaaaaaaa11"
        //  }
        // }
        $dummyPersonAuthTokenValidUntil2050 = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczovL3VuaXQtdGVz' .
            'dC1mYWtlLWlkLXN1Yi50aGViaWdnaXZldGVzdC5vcmcudWsiLCJpYXQiOjE2NjM0MzYxNTQsImV4cCI6MjUyNDYwODAwMCwic3ViIjp7' .
            'InBlcnNvbl9pZCI6IjEyMzQ1Njc4LTEyMzQtMTIzNC0xMjM0LTEyMzQ1Njc4OTBhYiIsImNvbXBsZXRlIjpmYWxzZSwicHNwX2lkIjoi' .
            'Y3VzX2FhYWFhYWFhYWFhYTExIn19.KdeGTDkkWCjI4-Kayay0LKn9TXziPXCUxxTPIZgGxxE';

        return $dummyPersonAuthTokenValidUntil2050;
    }

    protected function getMinimalCampaign(): Campaign
    {
        $charity = new Charity();
        $campaign = new Campaign();
        $campaign->setCharity($charity);

        return $campaign;
    }
}
