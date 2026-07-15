<?php

namespace MatchBot\IntegrationTests;

use MatchBot\Application\Commands\CallFrequentTasks;
use MatchBot\Application\Commands\CancelStaleDonationFundTips;
use MatchBot\Application\Commands\DeleteOldTestFunds;
use MatchBot\Application\Commands\ExpireMatchFunds;
use MatchBot\Application\Commands\ExpirePendingMandates;
use MatchBot\Application\Commands\SendStatistics;
use MatchBot\Application\Commands\UpdateApproxCampaignStatus;
use MatchBot\Application\Commands\UpdateCampaignDonationStats;
use MatchBot\Application\Commands\HandleOutOfSyncFunds;
use MatchBot\Application\Messenger\CommandRequest;
use MatchBot\Application\Messenger\Handler\CommandRequestHandler;
use MatchBot\Tests\Application\Commands\AlwaysAvailableLockStore;
use MatchBot\Tests\TestLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Lock\LockFactory;
use Prophecy\Argument;
use Aws\CloudWatch\CloudWatchClient;
use MatchBot\Application\Environment;
use MatchBot\Domain\DonationRepository;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Notifier\ChatterInterface;

class RunConsoleCommandTest extends IntegrationTest
{
    public function testItRunsSupportedCommandAndFailsOnUnknown(): void
    {
        $lockFactory = new LockFactory(new AlwaysAvailableLockStore());
        $app = $this->buildMinimalApp($lockFactory);

        $tickCommand = new CallFrequentTasks();
        $tickCommand->setApplication($app);
        $tickCommand->setLockFactory($lockFactory);
        $app->add($tickCommand);

        $handler = new CommandRequestHandler(
            consoleApplication: $app,
            chatter: $this->createStub(ChatterInterface::class),
            environment: Environment::Test,
            logger: new NullLogger()
        );

        // 1. Check supported command runs ok
        $handler(new CommandRequest('matchbot:tick'));
        // Continuing means it didn't throw. Explicit `assertTrue()` falls foul of phpstan rules.

        // 2. Check supported command with argument runs ok
        $handler(new CommandRequest('matchbot:handle-out-of-sync-funds check'));
        // Continuing means it didn't throw. Explicit `assertTrue()` falls foul of phpstan rules.
//
        // final check commented out for now while we try having exceptions caught instead.
//        // 3. Check unknown string fails and bails out
//        $this->expectException(CommandNotFoundException::class);
//        $this->expectExceptionMessage('Command "unknown-command" is not defined.');
//
//        $handler(new CommandRequest('unknown-command'));
    }

    private function buildMinimalApp(LockFactory $lockFactory): Application
    {
        $app = new Application();

        $commands = [
            new SendStatistics(
                new NativeClock(),
                $this->getMockCloudWatchClient(),
                $this->getService(DonationRepository::class),
                $this->getService(Environment::class),
            ),
            $this->getService(DeleteOldTestFunds::class),
            $this->getService(ExpireMatchFunds::class),
            $this->getService(CancelStaleDonationFundTips::class),
            $this->getService(ExpirePendingMandates::class),
            $this->getService(UpdateCampaignDonationStats::class),
            $this->getService(UpdateApproxCampaignStatus::class),
            $this->getService(HandleOutOfSyncFunds::class),
        ];

        foreach ($commands as $command) {
            $command->setLockFactory($lockFactory);
            $app->add($command);
        }

        return $app;
    }

    private function getMockCloudWatchClient(): CloudWatchClient
    {
        $cloudWatchClientProphecy = $this->prophesize(CloudWatchClient::class);
        $cloudWatchClientProphecy->putMetricData(Argument::type('array'))->shouldBeCalled();

        return $cloudWatchClientProphecy->reveal();
    }
}
