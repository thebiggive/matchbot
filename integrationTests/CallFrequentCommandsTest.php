<?php

namespace MatchBot\IntegrationTests;

use Aws\CloudWatch\CloudWatchClient;
use MatchBot\Application\Commands\CallFrequentTasks;
use MatchBot\Application\Commands\CancelStaleDonationFundTips;
use MatchBot\Application\Commands\ExpireMatchFunds;
use MatchBot\Application\Commands\ExpirePendingMandates;
use MatchBot\Application\Commands\SendStatistics;
use MatchBot\Application\Commands\UpdateCampaignDonationStats;
use MatchBot\Application\Environment;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\Application\Commands\AlwaysAvailableLockStore;
use Prophecy\Argument;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Lock\LockFactory;

class CallFrequentCommandsTest extends IntegrationTest
{
    public function testTick(): void
    {
        // arrange
        $lockFactory = new LockFactory(new AlwaysAvailableLockStore());
        $output = new BufferedOutput();
        $application = $this->buildMinimalApp($lockFactory);


        $command = new CallFrequentTasks();
        $command->setApplication($application);
        $command->setLockFactory($lockFactory);

        // pre-run same command to make sure any donations ready to expire are expired before the main test
        // so that we always see "Released 0 donations' matching" in the output on the next run (unless expiration
        // happens to become due between the next to lines but that's very unlikely)
        $command->run(new ArrayInput([]), new NullOutput());

        // act
        $command->run(new ArrayInput([]), $output);

        // assert
        $expectedOutput = implode(\PHP_EOL, [
            'matchbot:tick starting!',
            'matchbot:send-statistics starting!',
            'Sent 2 metrics to CloudWatch',
            'matchbot:send-statistics complete!',
            'matchbot:expire-match-funds starting!',
            'Released 0 donations\' matching',
            'matchbot:expire-match-funds complete!',
            'matchbot:expire-pending-mandates starting!',
            'matchbot:expire-pending-mandates complete!',
            'matchbot:cancel-stale-donation-fund-tips starting!',
            'matchbot:cancel-stale-donation-fund-tips complete!',
            'matchbot:update-campaign-donation-stats starting!',
            'Updated statistics for 0 campaigns with recent donations',
            'matchbot:update-campaign-donation-stats complete!',
            'matchbot:tick complete!',
            '',
        ]);
        $this->assertSame($expectedOutput, $output->fetch());
    }

    /**
     * For now, copy just the parts of `matchbot-cli.php` needed to test this command. Might be interesting
     * to explore putting `Application` in the general DI at some point instead.
     */
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
            $this->getService(ExpireMatchFunds::class),
            $this->getService(CancelStaleDonationFundTips::class),
            $this->getService(ExpirePendingMandates::class),
            $this->getService(UpdateCampaignDonationStats::class),
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
