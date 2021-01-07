<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use MatchBot\Application\Commands\PushDonations;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

class PushDonationsTest extends TestCase
{
    use ProphecyTrait;

    public function testSinglePush(): void
    {
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->pushAllPending()
            ->willReturn(1)
            ->shouldBeCalledOnce();

        $command = new PushDonations($donationRepoProphecy->reveal());
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));

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
}
