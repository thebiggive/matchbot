<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use MatchBot\Application\Commands\PushDonations;
use MatchBot\Client\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\RoutableMessageBus;

class PushDonationsTest extends TestCase
{
    public function testSinglePush(): void
    {
        list($bus, $now, $donationRepoProphecy) = $this->getTestDoubles(numberCancelled: 0);

        $command = new PushDonations(
            bus: $bus,
            now: $now,
            donationRepository: $donationRepoProphecy->reveal(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:push-donations starting!',
            'Pushed 1 donations to Salesforce',
            'matchbot:push-donations complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testPushWithOneCancelledAbandonedDonation(): void
    {
        list($bus, $now, $donationRepoProphecy) = $this->getTestDoubles(numberCancelled: 1);

        $command = new PushDonations(
            bus: $bus,
            now: $now,
            donationRepository: $donationRepoProphecy->reveal(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:push-donations starting!',
            'Abandoned 1 old Cancelled donations from Salesforce push',
            'Pushed 1 donations to Salesforce',
            'matchbot:push-donations complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    /**
     * @return list{
     *     \Symfony\Component\Messenger\RoutableMessageBus,
     *     \DateTimeImmutable,
     *     \Prophecy\Prophecy\ObjectProphecy<\MatchBot\Domain\DonationRepository>
     * }
     */
    public function getTestDoubles(int $numberCancelled): array
    {
        $bus = $this->prophesize(RoutableMessageBus::class)->reveal();
        $now = new \DateTimeImmutable();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->abandonOldCancelled()
            ->willReturn($numberCancelled)
            ->shouldBeCalledOnce();
        $donationRepoProphecy->pushSalesforcePending($now, $bus)
            ->willReturn(1)
            ->shouldBeCalledOnce();

        return [$bus, $now, $donationRepoProphecy];
    }
}
