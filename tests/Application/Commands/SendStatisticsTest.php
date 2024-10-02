<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use Aws\CloudWatch\CloudWatchClient;
use MatchBot\Application\Commands\SendStatistics;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

class SendStatisticsTest extends TestCase
{
    public function testZeroStatsPushTwoFigures(): void
    {
        $startOfThisMinute = new \DateTimeImmutable('@' . (time() - (time() % 60)));
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->getRecentHighVolumeCompletionRatio($startOfThisMinute)
            ->willReturn(null);
        $donationRepoProphecy->countDonationsJustCreated($startOfThisMinute)
            ->willReturn(0);
        $donationRepoProphecy->countDonationsJustCollected($startOfThisMinute)
            ->willReturn(0);

        $cloudWatchClientProphecy = $this->prophesize(CloudWatchClient::class);
        $cloudWatchClientProphecy->putMetricData([
            'Namespace' => 'TbgMatchBot',
            'MetricData' => [
                [
                    'MetricName' => 'tbg-test-DonationsCreated',
                    'Value' => 0,
                    'Timestamp' => $startOfThisMinute,
                ],
                [
                    'MetricName' => 'tbg-test-DonationsCollected',
                    'Value' => 0,
                    'Timestamp' => $startOfThisMinute,
                ],
            ],
        ])->shouldBeCalledOnce();

        $command = new SendStatistics(
            cloudWatchClient: $cloudWatchClientProphecy->reveal(),
            donationRepository: $donationRepoProphecy->reveal(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:send-statistics starting!',
            'Sent 2 metrics to CloudWatch',
            'matchbot:send-statistics complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testBusyStatsPushThreeFigures(): void
    {
        $startOfThisMinute = new \DateTimeImmutable('@' . (time() - (time() % 60)));
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->getRecentHighVolumeCompletionRatio($startOfThisMinute)
            ->willReturn(0.6543);
        $donationRepoProphecy->countDonationsJustCreated($startOfThisMinute)
            ->willReturn(700);
        $donationRepoProphecy->countDonationsJustCollected($startOfThisMinute)
            ->willReturn(100);

        $cloudWatchClientProphecy = $this->prophesize(CloudWatchClient::class);
        $cloudWatchClientProphecy->putMetricData([
            'Namespace' => 'TbgMatchBot',
            'MetricData' => [
                [
                    'MetricName' => 'tbg-test-DonationsCreated',
                    'Value' => 700,
                    'Timestamp' => $startOfThisMinute,
                ],
                [
                    'MetricName' => 'tbg-test-DonationsCollected',
                    'Value' => 100,
                    'Timestamp' => $startOfThisMinute,
                ],
                [
                    'MetricName' => 'tbg-test-CompletionRate',
                    'Value' => 0.6543,
                    'Timestamp' => $startOfThisMinute,
                ],
            ],
        ])->shouldBeCalledOnce();

        $command = new SendStatistics(
            cloudWatchClient: $cloudWatchClientProphecy->reveal(),
            donationRepository: $donationRepoProphecy->reveal(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:send-statistics starting!',
            'Sent 3 metrics to CloudWatch',
            'matchbot:send-statistics complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }
}
