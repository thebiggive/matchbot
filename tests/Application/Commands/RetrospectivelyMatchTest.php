<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use DateTime;
use MatchBot\Application\Commands\RetrospectivelyMatch;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Notifier\ChatterInterface;

class RetrospectivelyMatchTest extends TestCase
{
    use DonationTestDataTrait;

    private ChatterInterface $chatter;

    /** @var ObjectProphecy<RoutableMessageBus> */
    private ObjectProphecy $messageBusProphecy;

    public function setUp(): void
    {
        $chatterProphecy = $this->prophesize(ChatterInterface::class);
        $this->chatter = $chatterProphecy->reveal();

        $this->messageBusProphecy = $this->prophesize(RoutableMessageBus::class);
        $this->messageBusProphecy->dispatch(Argument::type(Envelope::class))->willReturnArgument();
    }

    /**
     * General `setUp()` has the repo method that this test relies on returning 1 donation.
     */
    public function testMissingDaysBackRunsInDefaultMode(): void
    {
        $command = new RetrospectivelyMatch(
            $this->getDonationRepo(true),
            $this->chatter,
            $this->messageBusProphecy->reveal()
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:retrospectively-match starting!',
            'Automatically evaluating campaigns which closed in the past hour',
            'Retrospectively matched 1 of 1 donations. £123.45 total new matching, across 1 campaigns.',
            'matchbot:retrospectively-match complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testNonWholeDaysBackIsRounded(): void
    {
        $command = new RetrospectivelyMatch(
            $this->getDonationRepo(false),
            $this->chatter,
            $this->messageBusProphecy->reveal()
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
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
        $command = new RetrospectivelyMatch(
            $this->getDonationRepo(false),
            $this->chatter,
            $this->messageBusProphecy->reveal()
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
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

    private function getDonationRepo(bool $matchingIsAllocated): DonationRepository
    {
        $donationRepo = $this->prophesize(DonationRepository::class);

        $donationRepo->findNotFullyMatchedToCampaignsWhichClosedSince(Argument::type(DateTime::class))
            ->willReturn([$this->getTestDonation()]);

        // Simulate specific day count mode not finding any campaigns to match, for now.
        $donationRepo->findRecentNotFullyMatchedToMatchCampaigns(Argument::type(DateTime::class))->willReturn([]);

        if ($matchingIsAllocated) {
            $donationRepo->allocateMatchFunds(Argument::type(Donation::class))
                ->shouldBeCalledOnce()
                ->willReturn('123.45');
            $donationRepo->push(Argument::type(Donation::class), false)
                ->shouldBeCalledOnce()
                ->willReturn(true);
        } else {
            $donationRepo->allocateMatchFunds(Argument::type(Donation::class))
                ->shouldNotBeCalled();
            $donationRepo->push(Argument::type(Donation::class), false)
                ->shouldNotBeCalled();
        }

        return $donationRepo->reveal();
    }
}
