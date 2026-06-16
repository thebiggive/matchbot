<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use MatchBot\Application\Commands\RetrospectivelyMatch;
use MatchBot\Domain\DonationRepository;
use MatchBot\IntegrationTests;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;

/**
 * This just covers command glue now since the real work is queued.
 * @see IntegrationTests\RetrospectivelyMatchCommandTest for the fuller test.
 */
class RetrospectivelyMatchTest extends TestCase
{
    use DonationTestDataTrait;

    /** @var ObjectProphecy<RoutableMessageBus> */
    private ObjectProphecy $messageBusProphecy;

    #[\Override]
    public function setUp(): void
    {
        $this->messageBusProphecy = $this->prophesize(RoutableMessageBus::class);
        $this->messageBusProphecy->dispatch(Argument::type(Envelope::class), Argument::cetera())->willReturnArgument();
    }

    /**
     * General `setUp()` has the repo method that this test relies on returning 1 donation.
     */
    public function testMissingDaysBackRunsInDefaultMode(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:retrospectively-match starting!',
            'Automatically evaluating campaigns which closed in the past hour',
            'matchbot:retrospectively-match complete!',
        ];
        $this->assertSame(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testNonWholeDaysBackIsRounded(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute(['days-back' => '7.5']);

        $expectedOutputLines = [
            'matchbot:retrospectively-match starting!',
            "Looking at past 8 days' donations",
            'matchbot:retrospectively-match complete!',
        ];
        $this->assertSame(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testWholeDaysBackProceeds(): void
    {
        $commandTester = $this->getCommandTester();
        $commandTester->execute(['days-back' => '8']);

        $expectedOutputLines = [
            'matchbot:retrospectively-match starting!',
            "Looking at past 8 days' donations",
            'matchbot:retrospectively-match complete!',
        ];
        $this->assertSame(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    private function getDonationRepo(): DonationRepository
    {
        $donationRepo = $this->prophesize(DonationRepository::class);

        $donationRepo->findNotFullyMatchedToCampaignsWhichClosedSince(Argument::type(\DateTimeImmutable::class))
            ->willReturn([$this->getTestDonation()]);

        // Simulate specific day count mode not finding any campaigns to match, for now.
        $donationRepo->findRecentNotFullyMatchedToMatchCampaigns(Argument::type(\DateTimeImmutable::class))->willReturn([]);

        return $donationRepo->reveal();
    }

    private function getCommandTester(): CommandTester
    {
        $command = new RetrospectivelyMatch(
            donationRepository: $this->getDonationRepo(),
            bus: $this->messageBusProphecy->reveal(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        return new CommandTester($command);
    }
}
