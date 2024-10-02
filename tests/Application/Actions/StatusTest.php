<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions;

use DI\Container;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Proxy\ProxyFactory;
use Doctrine\ORM\Tools\Console\Command\GenerateProxiesCommand;
use Doctrine\ORM\Tools\Console\ConsoleRunner;
use Doctrine\ORM\UnitOfWork;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\NullOutput;

use function assert;

class StatusTest extends TestCase
{
    private const string DOMAIN_DIR = __DIR__ . '/../../../src/Domain';

    public function setUp(): void
    {
        $this->generateORMProxiesAtRealPath();
    }

    public function testOK(): void
    {
        $app = $this->getAppInstance();

        $entityManager = $this->getConnectedMockEntityManager();
        $container = $app->getContainer();
        assert($container instanceof Container);
        $container->set(EntityManagerInterface::class, $entityManager);

        $request = $this->createRequest('GET', '/ping');
        $response = $app->handle($request);
        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(200, ['status' => 'OK']);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals($expectedSerialised, $payload);
    }

    public function testRedisErrorWithDummyHostname(): void
    {
        $app = $this->getAppInstance(true); // Use real Redis for this test

        $entityManager = $this->getConnectedMockEntityManager();
        $container = $app->getContainer();
        assert($container instanceof Container);
        $container->set(EntityManagerInterface::class, $entityManager);

        $request = $this->createRequest('GET', '/ping');
        $response = $app->handle($request);
        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(500, ['error' => 'Redis not connected']);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals($expectedSerialised, $payload);
    }

    public function testMissingDoctrineORMProxy(): void
    {
        $app = $this->getAppInstance();

        // Use a deliberately wrong path so proxies are absent.
        $entityManager = $this->getConnectedMockEntityManager('/tmp/not/this/dir/proxies');
        $container = $app->getContainer();
        assert($container instanceof Container);
        $container->set(EntityManagerInterface::class, $entityManager);

        $request = $this->createRequest('GET', '/ping');
        $response = $app->handle($request);
        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(500, ['error' => 'Doctrine proxies not built']);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals($expectedSerialised, $payload);
    }

    private function getConnectedMockEntityManager(
        string $proxyPath = __DIR__ . '/../../../var/doctrine/proxies',
    ): EntityManagerInterface {
        $cacheAdapter = new ArrayAdapter();

        $config = ORMSetup::createAttributeMetadataConfiguration(
            [self::DOMAIN_DIR],
            false, // Simulate live mode for these tests.
            $proxyPath,
            $cacheAdapter,
        );

        // No auto-generation – like live mode – for these tests.
        $config->setAutoGenerateProxyClasses(false);
        $config->setMetadataDriverImpl(
            new AttributeDriver([self::DOMAIN_DIR]),
        );
        $config->setMetadataCache($cacheAdapter);

        $connectionProphecy = $this->prophesize(Connection::class);
        $connectionProphecy->executeQuery('SELECT 1')->willReturn(
            $this->createStub(Result::class)
        );
        $connectionProphecy->isConnected()
            ->willReturn(true);
        // *Can* be called by `GenerateProxiesCommand`.
        $connectionProphecy->getDatabasePlatform()
            ->willReturn(new MySQL80Platform());

        $emProphecy = $this->prophesize(EntityManagerInterface::class);
        $emProphecy->getConfiguration()
            ->willReturn($config);

        $classMetadataFactory = new ClassMetadataFactory();
        // This has to be set on both sides for `ClassMetadataFactory::initialize()` not to crash.
        $classMetadataFactory->setEntityManager($emProphecy->reveal());
        // *Can* be called by `GenerateProxiesCommand`.
        $emProphecy->getMetadataFactory()
            ->willReturn($classMetadataFactory);

        // *Can* be called by `GenerateProxiesCommand`.
        $emProphecy->getEventManager()
            ->willReturn(new EventManager());

        // *Can* be called by `GenerateProxiesCommand`.
        // Mirrors the instantiation in concrete `EntityManager`'s constructor.
        $emProphecy->getUnitOfWork()
            ->shouldBeCalledOnce()
            ->willReturn(new UnitOfWork($emProphecy->reveal()));

        // Mirrors the instantiation in concrete `EntityManager`'s constructor.
        $proxyFactory = new ProxyFactory(
            $emProphecy->reveal(),
            $config->getProxyDir() ?? throw new \Exception('Missing proxy dir'),
            $config->getProxyNamespace() ?? throw new \Exception('missing proxy namespace'),
            $config->getAutoGenerateProxyClasses()
        );
        // *Can* be called by `GenerateProxiesCommand`.
        $emProphecy->getProxyFactory()
            ->willReturn($proxyFactory);

        $emProphecy->getConnection()
            ->shouldBeCalledOnce()
            ->willReturn($connectionProphecy->reveal());

        return $emProphecy->reveal();
    }

    /**
     * Simulate the real app entrypoint's Doctrine proxy generate command, so that proxies are
     * in-place in the unit test filesystem and we can assume that when realistic paths are provided,
     * the `Status` Action should be able to complete a successful run through.
     */
    private function generateORMProxiesAtRealPath(): void
    {
        $app = $this->getAppInstance();
        $container = $app->getContainer();
        assert($container instanceof Container);

        $container->set(EntityManagerInterface::class, $this->getConnectedMockEntityManager());

        /** @psalm-suppress DeprecatedMethod - using Deprecated methods is almost OK in tests */
        $helperSet = ConsoleRunner::createHelperSet($container->get(EntityManagerInterface::class));
        $generateProxiesCommand = new GenerateProxiesCommand();
        $generateProxiesCommand->setHelperSet($helperSet);
        $generateProxiesCommand->run(
            new StringInput(''),
            new NullOutput(),
        );
    }
}
