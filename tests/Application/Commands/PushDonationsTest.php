<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use MatchBot\Application\Commands\PushDonations;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\RoutableMessageBus;

class PushDonationsTest extends TestCase
{
    public function testSinglePush(): void
    {
        $bus = $this->prophesize(RoutableMessageBus::class)->reveal();
        $now = new \DateTimeImmutable();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->abandonOldCancelled()
            ->willReturn(0)
            ->shouldBeCalledOnce();
        $donationRepoProphecy->pushSalesforcePending(now: $now, bus: $bus,)
            ->willReturn(1)
            ->shouldBeCalledOnce();

        $command = new PushDonations(bus: $bus, now: $now, donationRepository: $donationRepoProphecy->reveal());
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
        $bus = $this->prophesize(RoutableMessageBus::class)->reveal();
        $now = new \DateTimeImmutable();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->abandonOldCancelled()
            ->willReturn(1)
            ->shouldBeCalledOnce();
        $donationRepoProphecy->pushSalesforcePending($now, $bus)
            ->willReturn(1)
            ->shouldBeCalledOnce();

        $command = new PushDonations($bus, $now, $donationRepoProphecy->reveal());
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
}
