<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use MatchBot\Application\Commands\ExpireMatchFunds;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

class ExpireMatchFundsTest extends TestCase
{
    public function testNoExpiries(): void
    {
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findWithExpiredMatching(Argument::type(\DateTimeImmutable::class))
            ->willReturn([])
            ->shouldBeCalledOnce();
        $donationRepoProphecy->releaseMatchFunds(Argument::type(Donation::class))->shouldNotBeCalled();

        $commandTester = new CommandTester($this->getCommand($donationRepoProphecy));
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:expire-match-funds starting!',
            "Released 0 donations' matching",
            'matchbot:expire-match-funds complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testTwoExpiries(): void
    {
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findWithExpiredMatching(Argument::type(\DateTimeImmutable::class))->willReturn([
            TestCase::someDonation('1'),
            TestCase::someDonation('1')
        ]);
        $donationRepoProphecy->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldBeCalledTimes(2);

        $commandTester = new CommandTester($this->getCommand($donationRepoProphecy));
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:expire-match-funds starting!',
            "Released 2 donations' matching",
            'matchbot:expire-match-funds complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    /**
     * @param ObjectProphecy<DonationRepository> $donationRepoProphecy
     * @return ExpireMatchFunds
     */
    private function getCommand(ObjectProphecy $donationRepoProphecy): ExpireMatchFunds
    {
        $command = new ExpireMatchFunds($donationRepoProphecy->reveal());
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        return $command;
    }
}
