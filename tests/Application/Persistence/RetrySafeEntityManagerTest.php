<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Persistence;

use DI\Container;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Repository\RepositoryFactory;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Psr\Log\NullLogger;

class RetrySafeEntityManagerTest extends TestCase
{
    private RetrySafeEntityManager $retrySafeEntityManager;

    public function setUp(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $repoFactoryProphecy = $this->prophesize(RepositoryFactory::class);
        $repoFactoryProphecy->getRepository(
            Argument::type(RetrySafeEntityManager::class),
            Donation::class,
        )->willReturn($this->prophesize(DonationRepository::class)->reveal());

        $config = $container->get(Configuration::class);
        $config->setRepositoryFactory($repoFactoryProphecy->reveal());

        $this->retrySafeEntityManager = new RetrySafeEntityManager(
            $config,
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
}
