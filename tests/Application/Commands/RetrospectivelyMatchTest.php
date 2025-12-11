<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Commands\RetrospectivelyMatch;
use MatchBot\Application\Matching\Allocator;
use MatchBot\Application\Matching\MatchFundsRedistributor;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\FundRepository;
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

    /**
     * @var ObjectProphecy<MatchFundsRedistributor>
     */
    private ObjectProphecy $matchFundsRedistributorProphecy;

    #[\Override]
    public function setUp(): void
    {
        $chatterProphecy = $this->prophesize(ChatterInterface::class);
        $this->chatter = $chatterProphecy->reveal();
        $this->messageBusProphecy = $this->prophesize(RoutableMessageBus::class);
        $this->messageBusProphecy->dispatch(Argument::type(Envelope::class), Argument::cetera())->willReturnArgument();
        $this->matchFundsRedistributorProphecy = $this->prophesize(MatchFundsRedistributor::class);
    }

    /**
     * General `setUp()` has the repo method that this test relies on returning 1 donation.
     */
    public function testMissingDaysBackRunsInDefaultMode(): void
    {
        $commandTester = $this->getCommandTester(matchingIsAllocated: true);
        $this->matchFundsRedistributorProphecy->redistributeMatchFunds(Argument::any())->shouldBeCalledOnce()->willReturn([3, 2]);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:retrospectively-match starting!',
            'Automatically evaluating campaigns which closed in the past hour',
            'Retrospectively matched 1 of 1 donations. £123.45 total new matching, across 1 campaigns.',
            'Checked 3 donations and redistributed matching for 2',
            'Pushed fund totals to Salesforce for 0 funds: ',
            'matchbot:retrospectively-match complete!',
        ];
        $this->assertSame(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testNonWholeDaysBackIsRounded(): void
    {
        $commandTester = $this->getCommandTester(matchingIsAllocated: false);
        $this->matchFundsRedistributorProphecy->redistributeMatchFunds(Argument::any())->shouldBeCalledOnce()->willReturn([3, 2]);
        $commandTester->execute(['days-back' => '7.5']);

        $expectedOutputLines = [
            'matchbot:retrospectively-match starting!',
            "Looking at past 8 days' donations",
            'Retrospectively matched 0 of 0 donations. £0.00 total new matching, across 0 campaigns.',
            'Checked 3 donations and redistributed matching for 2',
            'Pushed fund totals to Salesforce for 0 funds: ',
            'matchbot:retrospectively-match complete!',
        ];
        $this->assertSame(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testWholeDaysBackProceeds(): void
    {
        $commandTester = $this->getCommandTester(matchingIsAllocated: false);
        $this->matchFundsRedistributorProphecy->redistributeMatchFunds(Argument::any())->shouldBeCalledOnce()->willReturn([3, 2]);
        $commandTester->execute(['days-back' => '8']);

        $expectedOutputLines = [
            'matchbot:retrospectively-match starting!',
            "Looking at past 8 days' donations",
            'Retrospectively matched 0 of 0 donations. £0.00 total new matching, across 0 campaigns.',
            'Checked 3 donations and redistributed matching for 2',
            'Pushed fund totals to Salesforce for 0 funds: ',
            'matchbot:retrospectively-match complete!',
        ];
        $this->assertSame(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    private function getAllocator(bool $matchingIsAllocated): Allocator
    {
        $allocatorProphecy = $this->prophesize(Allocator::class);

        if ($matchingIsAllocated) {
            $allocatorProphecy->allocateMatchFunds(Argument::type(Donation::class))
                ->shouldBeCalledOnce()
                ->willReturn('123.45');
        } else {
            $allocatorProphecy->allocateMatchFunds(Argument::type(Donation::class))
                ->shouldNotBeCalled();
        }

        return $allocatorProphecy->reveal();
    }

    private function getDonationRepo(): DonationRepository
    {
        $donationRepo = $this->prophesize(DonationRepository::class);

        $donationRepo->findNotFullyMatchedToCampaignsWhichClosedSince(Argument::type(DateTime::class))
            ->willReturn([$this->getTestDonation()]);

        // Simulate specific day count mode not finding any campaigns to match, for now.
        $donationRepo->findRecentNotFullyMatchedToMatchCampaigns(Argument::type(DateTime::class))->willReturn([]);

        return $donationRepo->reveal();
    }

    private function getCommandTester(bool $matchingIsAllocated): CommandTester
    {
        $command = new RetrospectivelyMatch(
            allocator: $this->getAllocator($matchingIsAllocated),
            donationRepository: $this->getDonationRepo(),
            fundRepository: $this->createStub(FundRepository::class),
            chatter: $this->chatter,
            bus: $this->messageBusProphecy->reveal(),
            entityManager: $this->createStub(EntityManagerInterface::class),
            matchFundsRedistributor: $this->matchFundsRedistributorProphecy->reveal()
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        return new CommandTester($command);
    }
}
