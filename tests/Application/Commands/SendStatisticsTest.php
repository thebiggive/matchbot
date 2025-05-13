<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use Aws\CloudWatch\CloudWatchClient;
use MatchBot\Application\Commands\SendStatistics;
use MatchBot\Application\Environment;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

class SendStatisticsTest extends TestCase
{
    private \DateTimeImmutable $startOfMockClocksCurrentMinute;

    #[\Override]
    public function setUp(): void
    {
        $this->startOfMockClocksCurrentMinute = new \DateTimeImmutable('@1727866800');

        parent::setUp();
    }

    public function testZeroStatsPushTwoFigures(): void
    {
        $end = $this->startOfMockClocksCurrentMinute;

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->getRecentHighVolumeCompletionRatio($end)->willReturn(null);
        $donationRepoProphecy->countDonationsCreatedInMinuteTo($end)->willReturn(0);
        $donationRepoProphecy->countDonationsCollectedInMinuteTo($end)->willReturn(0);

        $cloudWatchClientProphecy = $this->prophesize(CloudWatchClient::class);
        $cloudWatchClientProphecy->putMetricData([
            'Namespace' => 'TbgMatchBot',
            'MetricData' => [
                [
                    'MetricName' => 'tbg-test-DonationsCreated',
                    'Value' => 0,
                    'Timestamp' => $end,
                ],
                [
                    'MetricName' => 'tbg-test-DonationsCollected',
                    'Value' => 0,
                    'Timestamp' => $end,
                ],
            ],
        ])->shouldBeCalledOnce();

        $command = new SendStatistics(
            clock: new MockClock('@1727866802'),
            cloudWatchClient: $cloudWatchClientProphecy->reveal(),
            donationRepository: $donationRepoProphecy->reveal(),
            environment: Environment::fromAppEnv('test'),
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
        $end = $this->startOfMockClocksCurrentMinute;

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->getRecentHighVolumeCompletionRatio($end)->willReturn(0.6543);
        $donationRepoProphecy->countDonationsCreatedInMinuteTo($end)->willReturn(700);
        $donationRepoProphecy->countDonationsCollectedInMinuteTo($end)->willReturn(100);

        $cloudWatchClientProphecy = $this->prophesize(CloudWatchClient::class);
        $cloudWatchClientProphecy->putMetricData([
            'Namespace' => 'TbgMatchBot',
            'MetricData' => [
                [
                    'MetricName' => 'tbg-test-DonationsCreated',
                    'Value' => 700,
                    'Timestamp' => $end,
                ],
                [
                    'MetricName' => 'tbg-test-DonationsCollected',
                    'Value' => 100,
                    'Timestamp' => $end,
                ],
                [
                    'MetricName' => 'tbg-test-CompletionRate',
                    'Value' => 0.6543,
                    'Timestamp' => $end,
                ],
            ],
        ])->shouldBeCalledOnce();

        $command = new SendStatistics(
            clock: new MockClock('@1727866802'),
            cloudWatchClient: $cloudWatchClientProphecy->reveal(),
            donationRepository: $donationRepoProphecy->reveal(),
            environment: Environment::fromAppEnv('test'),
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
