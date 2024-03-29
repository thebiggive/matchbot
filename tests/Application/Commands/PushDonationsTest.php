<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use MatchBot\Application\Commands\PushDonations;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

class PushDonationsTest extends TestCase
{
    public function testSinglePush(): void
    {
        $now = new \DateTimeImmutable();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->abandonOldCancelled()
            ->willReturn(0)
            ->shouldBeCalledOnce();
        $donationRepoProphecy->pushSalesforcePending($now)
            ->willReturn(1)
            ->shouldBeCalledOnce();

        $command = new PushDonations($now, $donationRepoProphecy->reveal());
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
        $now = new \DateTimeImmutable();
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->abandonOldCancelled()
            ->willReturn(1)
            ->shouldBeCalledOnce();
        $donationRepoProphecy->pushSalesforcePending($now)
            ->willReturn(1)
            ->shouldBeCalledOnce();

        $command = new PushDonations($now, $donationRepoProphecy->reveal());
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
