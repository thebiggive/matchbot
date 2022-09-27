<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Tools\Setup;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Tests\TestCase;

class StatusTest extends TestCase
{
    public function testOK(): void
    {
        $app = $this->getAppInstance();

        $entityManager = $this->getConnectedMockEntityManager();
        $app->getContainer()->set(EntityManagerInterface::class, $entityManager);

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
        $app->getContainer()->set(EntityManagerInterface::class, $entityManager);

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
        $app->getContainer()->set(EntityManagerInterface::class, $entityManager);

        $request = $this->createRequest('GET', '/ping');
        $response = $app->handle($request);
        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(500, ['error' => 'Doctrine proxies not built']);
        $expectedSerialised = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals(500, $response->getStatusCode());
        $this->assertEquals($expectedSerialised, $payload);
    }

    private function getConnectedMockEntityManager(string $proxyPath = '/var/www/html/var/doctrine/proxies'): EntityManagerInterface
    {
        $cache = new ArrayCache();
        $ormConfigWithNonStandardProxyVars = Setup::createAnnotationMetadataConfiguration(
            ['/var/www/html/src/Domain'],
            false, // Simulate live mode for these tests.
            $proxyPath,
            $cache,
        );

        // No auto-generation – like live mode – for these tests.
        $ormConfigWithNonStandardProxyVars->setAutoGenerateProxyClasses(false);
        $ormConfigWithNonStandardProxyVars->setMetadataDriverImpl(
            new AnnotationDriver(new AnnotationReader(), ['/var/www/html/src/Domain']),
        );
        $ormConfigWithNonStandardProxyVars->setMetadataCacheImpl($cache);

        $connectionProphecy = $this->prophesize(Connection::class);
        $connectionProphecy->isConnected()
            ->shouldBeCalledOnce()
            ->willReturn(true);

        $emProphecy = $this->prophesize(EntityManagerInterface::class);
        $emProphecy->getConfiguration()
            ->shouldBeCalledOnce()
            ->willReturn($ormConfigWithNonStandardProxyVars);

        $emProphecy->getConnection()
            ->shouldBeCalledOnce()
            ->willReturn($connectionProphecy->reveal());

        return $emProphecy->reveal();
    }
}
