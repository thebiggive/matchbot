<?php

namespace MatchBot\IntegrationTests;

use CreateDonationTest;
use IntegrationTests;
use LosMiddleware\RateLimit\RateLimitMiddleware;
use MatchBot\Application\Auth\DonationRecaptchaMiddleware;
use PHPUnit\Framework\TestCase;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Slim\App;
use Slim\Factory\AppFactory;
use Stripe\StripeClient;

abstract class IntegrationTest extends TestCase
{
    public static ?ContainerInterface $integrationTestContainer = null;
    public static ?App $app = null;

    public function setUp(): void
    {
        parent::setUp();

        $noOpMiddlware = new class implements MiddlewareInterface {
            public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
            {
                return $handler->handle($request);
            }
        };

        $container = require __DIR__ . '/../bootstrap.php';
        IntegrationTest::setContainer($container);
        $container->set(DonationRecaptchaMiddleware::class, $noOpMiddlware);
        $container->set(RateLimitMiddleware::class, $noOpMiddlware);
        $container->set(\Psr\Log\LoggerInterface::class, new \Psr\Log\NullLogger());

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

    protected function getContainer(): ContainerInterface
    {
        if (self::$integrationTestContainer === null) {
            throw new \Exception("Test container not set");
        }
        return self::$integrationTestContainer;
    }

    public function db(): \Doctrine\DBAL\Connection
    {
        return $this->getService(\Doctrine\ORM\EntityManagerInterface::class)->getConnection();
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
     */ public function getService(string $name): mixed
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
        $fakeStripeClient->paymentMethods = $stripePaymentMethodServiceProphecy->reveal();
        $fakeStripeClient->customers = $stripeCustomerServiceProphecy->reveal();
        $fakeStripeClient->paymentIntents =$stripePaymentIntents->reveal();

        return $fakeStripeClient;
    }
}
