<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use DateTime;
use MatchBot\Application\Commands\RetrospectivelyMatch;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Notifier\ChatterInterface;

/**
 * TODO tests should also cover the case where there are actual donations to match, rather than solely input param
 * handling.
 */
class RetrospectivelyMatchTest extends TestCase
{
    private RetrospectivelyMatch $command;

    public function setUp(): void
    {
        $donationRepo = $this->prophesize(DonationRepository::class);
        // Simulate not finding any campaigns to match, for now. This test's focus is the Command's own argument logic.
        $donationRepo->findNotFullyMatchedToCampaignsWhichClosedSince(Argument::type(DateTime::class))->willReturn([]);
        $donationRepo->findRecentNotFullyMatchedToMatchCampaigns(Argument::type(DateTime::class))->willReturn([]);

        $chatterProphecy = $this->prophesize(ChatterInterface::class);

        $this->command = new RetrospectivelyMatch($donationRepo->reveal(), $chatterProphecy->reveal());
        $this->command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $this->command->setLogger(new NullLogger());
    }

    public function testMissingDaysBackRunsInDefaultMode(): void
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:retrospectively-match starting!',
            'Automatically evaluating campaigns which closed in the past hour',
            'Retrospectively matched 0 of 0 donations. £0.00 total new matching, across 0 campaigns.',
            'matchbot:retrospectively-match complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testNonWholeDaysBackIsRounded(): void
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['days-back' => '7.5']);

        $expectedOutputLines = [
            'matchbot:retrospectively-match starting!',
            "Looking at past 8 days' donations",
            'Retrospectively matched 0 of 0 donations. £0.00 total new matching, across 0 campaigns.',
            'matchbot:retrospectively-match complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testWholeDaysBackProceeds(): void
    {
        $commandTester = new CommandTester($this->command);
        $commandTester->execute(['days-back' => '8']);

        $expectedOutputLines = [
            'matchbot:retrospectively-match starting!',
            "Looking at past 8 days' donations",
            'Retrospectively matched 0 of 0 donations. £0.00 total new matching, across 0 campaigns.',
            'matchbot:retrospectively-match complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }
}
