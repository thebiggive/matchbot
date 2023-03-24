<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Persistence;

use DI\Container;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Exception\EntityManagerClosed;
use Doctrine\ORM\Repository\RepositoryFactory;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\TestCase;
use PHPUnit\Framework\MockObject\Stub\ReturnCallback;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use ReflectionClass;

class RetrySafeEntityManagerTest extends TestCase
{
    private RetrySafeEntityManager $retrySafeEntityManager;

    public function setUp(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $this->retrySafeEntityManager = new RetrySafeEntityManager(
            $this->getConfiguration($container),
            $container->get('settings')['doctrine']['connection'],
            new NullLogger(),
        );
        $container->set(RetrySafeEntityManager::class, $this->retrySafeEntityManager);
    }

    public function testBuild(): void
    {
        // Just check a construct + EM rebuild doesn't crash.
        $this->retrySafeEntityManager->resetManager();

        $this->addToAssertionCount(1);
    }

    public function testGetRepository(): void
    {
        $repo = $this->retrySafeEntityManager->getRepository(Donation::class);

        $this->assertInstanceOf(DonationRepository::class, $repo);
    }

    public function testPersist(): void
    {
        $underlyingEmProphecy = $this->prophesize(EntityManager::class);
        $underlyingEmProphecy->persist(Argument::type(Donation::class))
            ->shouldBeCalledOnce();

        $retrySafeEntityManagerReflected = new ReflectionClass($this->retrySafeEntityManager);

        $emProperty = $retrySafeEntityManagerReflected->getProperty('entityManager');
        $emProperty->setValue($this->retrySafeEntityManager, $underlyingEmProphecy->reveal());

        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $container->set(EntityManager::class, $underlyingEmProphecy->reveal());

        $this->retrySafeEntityManager->persist(Donation::onePoundTestDonation());
    }

    public function testPersistWithRetry(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        // First underlying EM should throw closed error.
        $underlyingEmProphecy1 = $this->prophesize(EntityManager::class);
        $underlyingEmProphecy1->persist(Argument::type(Donation::class))
            ->willThrow(new EntityManagerClosed())
            ->shouldBeCalledOnce();

        // Second should work.
        $underlyingEmProphecy2 = $this->prophesize(EntityManager::class);
        $underlyingEmProphecy2->persist(Argument::type(Donation::class))
            ->shouldBeCalledOnce();

        $this->retrySafeEntityManager = $this->getRetrySafeEntityManagerPartialMock(
            $this->getConfiguration($container),
            $container->get('settings')['doctrine']['connection'],
            $underlyingEmProphecy1->reveal(),
            $underlyingEmProphecy2->reveal(),
        );

        $container->set(EntityManager::class, $underlyingEmProphecy1->reveal());
        $container->set(RetrySafeEntityManager::class, $this->retrySafeEntityManager);
        $container->set(EntityManagerInterface::class, $this->retrySafeEntityManager);

        $this->retrySafeEntityManager->persist(Donation::onePoundTestDonation());
    }

    public function testFlush(): void
    {
        $underlyingEmProphecy = $this->prophesize(EntityManager::class);
        $underlyingEmProphecy->flush(null)
            ->shouldBeCalledOnce();

        $retrySafeEntityManagerReflected = new ReflectionClass($this->retrySafeEntityManager);

        $emProperty = $retrySafeEntityManagerReflected->getProperty('entityManager');
        $emProperty->setValue($this->retrySafeEntityManager, $underlyingEmProphecy->reveal());

        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();
        $container->set(EntityManager::class, $underlyingEmProphecy->reveal());

        $this->retrySafeEntityManager->flush();
    }

    public function testFlushWithRetry(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        // First underlying EM should throw closed error.
        $underlyingEmProphecy1 = $this->prophesize(EntityManager::class);
        $underlyingEmProphecy1->flush(null)
            ->willThrow(new EntityManagerClosed())
            ->shouldBeCalledOnce();

        // Second should work.
        $underlyingEmProphecy2 = $this->prophesize(EntityManager::class);
        $underlyingEmProphecy2->flush(null)->shouldBeCalledOnce();

        $this->retrySafeEntityManager = $this->getRetrySafeEntityManagerPartialMock(
            $this->getConfiguration($container),
            $container->get('settings')['doctrine']['connection'],
            $underlyingEmProphecy1->reveal(),
            $underlyingEmProphecy2->reveal(),
        );

        $container->set(EntityManager::class, $underlyingEmProphecy1->reveal());
        $container->set(RetrySafeEntityManager::class, $this->retrySafeEntityManager);
        $container->set(EntityManagerInterface::class, $this->retrySafeEntityManager);

        $this->retrySafeEntityManager->flush();
    }

    /**
     * Because we need to mock `resetManager()` in this test – as it's called in the
     * midst of methods we must test – and also need to make real calls to those methods
     * (e.g. persist() and flush()), this test has some rare cases where we use *partial*
     * mocks instead of Prophecy to stub out only the method we must fake.
     *
     * @param Configuration $config
     * @param array         $connectionSettings
     * @param EntityManager $underlyingEmToFailInitially
     * @param EntityManager $underlyingEmToResetTo
     */
    private function getRetrySafeEntityManagerPartialMock(
        Configuration $config,
        array $connectionSettings,
        EntityManager $underlyingEmToFailInitially,
        EntityManager $underlyingEmToResetTo,
    ): RetrySafeEntityManager {
        $mockBuilder = $this->getMockBuilder(RetrySafeEntityManager::class);
        $mockBuilder->setConstructorArgs([
            $config,
            $connectionSettings,
            new NullLogger(),
        ]);

        $mockBuilder->onlyMethods(['resetManager']);

        $retrySafeEm = $mockBuilder->getMock();

        $retrySafeEm->setEntityManager($underlyingEmToFailInitially);

        $retrySafeEm->expects($this->once())
            ->method('resetManager')
            ->will(new ReturnCallback(static function () use ($retrySafeEm, $underlyingEmToResetTo) {
                $retrySafeEm->setEntityManager($underlyingEmToResetTo);
            }));

        return $retrySafeEm;
    }

    private function getConfiguration(Container $container): Configuration
    {
        $repoFactoryProphecy = $this->prophesize(RepositoryFactory::class);
        $repoFactoryProphecy->getRepository(
            Argument::type(RetrySafeEntityManager::class),
            Donation::class,
        )->willReturn($this->prophesize(DonationRepository::class)->reveal());

        $config = $container->get(Configuration::class);
        $config->setRepositoryFactory($repoFactoryProphecy->reveal());

        return $config;
    }
}
