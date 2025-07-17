<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Commands\RedistributeMatchFunds;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching\Allocator;
use MatchBot\Application\Matching\MatchFundsRedistributor;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\Fund;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\FundType;
use MatchBot\Domain\PersonId;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Notifier\ChatterInterface;

class RedistributeMatchFundsTest extends TestCase
{
    private \DateTimeImmutable $newYearsEveNoon;
    private \DateTimeImmutable $earlyNovemberNoon;
    /** @var ObjectProphecy<RoutableMessageBus> */
    private ObjectProphecy $messageBusProphecy;


    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->newYearsEveNoon = new \DateTimeImmutable('2023-12-31T12:00:00');
        $this->earlyNovemberNoon = new \DateTimeImmutable('2023-11-05T12:00:00');

        $this->messageBusProphecy = $this->prophesize(RoutableMessageBus::class);
    }

    public function testNoEligibleDonations(): void
    {
        $allocatorProphecy = $this->prophesize(Allocator::class);
        $allocatorProphecy->releaseMatchFunds(Argument::type(Donation::class))->shouldNotBeCalled();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findWithMatchingWhichCouldBeReplacedWithHigherPriorityAllocation(
            $this->newYearsEveNoon,
            $this->earlyNovemberNoon,
        )
            ->willReturn([])
            ->shouldBeCalledOnce();

        $this->messageBusProphecy->dispatch(Argument::type(Envelope::class))->shouldNotBeCalled();

        $commandTester = new CommandTester($this->getCommand(
            $allocatorProphecy,
            $this->prophesize(CampaignFundingRepository::class),
            $this->newYearsEveNoon,
            $donationRepoProphecy,
            $this->prophesize(LoggerInterface::class),
        ));
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:redistribute-match-funds starting!',
            'Checked 0 donations and redistributed matching for 0',
            'matchbot:redistribute-match-funds complete!',
        ];
        $this->assertSame(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    /**
     * @return list<array{0: FundType, 1: FundType, 2: bool}>
     */
    public function redistributionByFundTypes(): array
    {
        return [
            // Original fund type used for donation, type of new fund available, funds should be redistributed?
            // ordinary pledges are highest priority, never redistribute away from pledge,
            [FundType::Pledge, FundType::Pledge, false],
            [FundType::Pledge, FundType::ChampionFund, false],
            [FundType::Pledge, FundType::TopupPledge, false],

            // champion funds are in the middle, only redistribute to pledge
            [FundType::ChampionFund, FundType::Pledge, true],
            [FundType::ChampionFund, FundType::ChampionFund, false],
            [FundType::ChampionFund, FundType::TopupPledge, false],

            // TopupPledge redistributes to Pledge or to Champion Fund.
            [FundType::TopupPledge, FundType::Pledge, true],
            [FundType::TopupPledge, FundType::ChampionFund, true],
            [FundType::TopupPledge, FundType::TopupPledge, false],
        ];
    }

    /**
     * @dataProvider redistributionByFundTypes
     */
    public function testOneDonationHasFundsUsedAndIsAssignedAccordingToFundTypesToFullMatchedValue(
        FundType $originalFundTypeUsed,
        FundType $availableFundType,
        bool $shouldRedistribute,
    ): void {
        $donation = $this->getTenPoundMatchedDonation($originalFundTypeUsed);

        $allocatorProphecy = $this->prophesize(Allocator::class);
        $allocatorProphecy->releaseMatchFunds($donation)
            ->shouldBeCalledTimes($shouldRedistribute ? 1 : 0);
        $allocatorProphecy->allocateMatchFunds($donation)
            ->shouldBeCalledTimes($shouldRedistribute ? 1 : 0)
            ->willReturn('10.00');

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findWithMatchingWhichCouldBeReplacedWithHigherPriorityAllocation(
            $this->newYearsEveNoon,
            $this->earlyNovemberNoon,
        )->willReturn([$donation]);

        $loggerProphecy = $this->prophesize(LoggerInterface::class);
        $loggerProphecy->error(Argument::type('string'))->shouldNotBeCalled();

        $this->messageBusProphecy->dispatch(Argument::type(Envelope::class))
            ->shouldBeCalledTimes($shouldRedistribute ? 1 : 0)
            ->willReturnArgument();

        $campaignFundingRepoProphecy = $this->prophesize(CampaignFundingRepository::class);
        $campaignFundingRepoProphecy->getAvailableFundings(Argument::type(Campaign::class))
            ->willReturn([$this->getFullyAvailableFunding($availableFundType)]);

        $commandTester = new CommandTester($this->getCommand(
            $allocatorProphecy,
            $campaignFundingRepoProphecy,
            $this->newYearsEveNoon,
            $donationRepoProphecy,
            $loggerProphecy,
        ));
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:redistribute-match-funds starting!',
            $shouldRedistribute ? 'Checked 1 donations and redistributed matching for 1' : 'Checked 1 donations and redistributed matching for 0',
            'matchbot:redistribute-match-funds complete!',
        ];
        $this->assertSame(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    /**
     * Cover the edge case – expected to be extremely rare – in which funds are 'claimed' in another thread before
     * redistribution is complete.
     *
     * In this example:
     * * the donation of £10 was matched by champion funds
     * * a pledge of £101 was fully available when the command started
     * * another thread, e.g. a £106 donation, claimed the champion funds and all but £5 of the pledge at a very
     *   unfortunate moment
     * * the previously matched donation was left with a £5 partial match from the pledge.
     *
     * In this scenario, the command continues without returning an error *code* but logs an error which will lead
     * to a Slack alarm, so we can decide how to follow up.
     */
    public function testOneDonationHasChampFundsUsedAndIsAssignedPledgeButOnlyPartMatched(): void
    {
        $donation = $this->getTenPoundMatchedDonation(FundType::ChampionFund);

        $allocatorProphecy = $this->prophesize(Allocator::class);
        $allocatorProphecy->releaseMatchFunds($donation)
            ->shouldBeCalledOnce();
        $allocatorProphecy->allocateMatchFunds($donation)
            ->shouldBeCalledOnce()
            ->willReturn('5.00'); // Half the donation matched after redistribution.

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findWithMatchingWhichCouldBeReplacedWithHigherPriorityAllocation(
            $this->newYearsEveNoon,
            $this->earlyNovemberNoon,
        )->willReturn([$donation]);

        $uuid = $donation->getUuid();
        $loggerProphecy = $this->prophesize(LoggerInterface::class);
        $loggerProphecy->error("Donation $uuid had redistributed match funds reduced from 10.00 to 5.00 (GBP)")
            ->shouldBeCalledOnce();

        $this->messageBusProphecy->dispatch(Argument::type(Envelope::class))->shouldBeCalledOnce()->willReturnArgument();

        $campaignFundingRepoProphecy = $this->prophesize(CampaignFundingRepository::class);
        $campaignFundingRepoProphecy->getAvailableFundings(Argument::type(Campaign::class))
            ->willReturn([$this->getFullyAvailableFunding(FundType::Pledge)]);

        $commandTester = new CommandTester($this->getCommand(
            $allocatorProphecy,
            $campaignFundingRepoProphecy,
            $this->newYearsEveNoon,
            $donationRepoProphecy,
            $loggerProphecy,
        ));
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:redistribute-match-funds starting!',
            'Checked 1 donations and redistributed matching for 1',
            'matchbot:redistribute-match-funds complete!',
        ];
        $this->assertSame(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    private function getFullyAvailableFunding(FundType $fundType): CampaignFunding
    {
        $pledgeAmount = '101.00';
        $pledge = new Fund(currencyCode: 'GBP', name: '', slug: null, salesforceId: null, fundType: $fundType);

        return new CampaignFunding(
            fund: $pledge,
            amount: $pledgeAmount,
            amountAvailable: $pledgeAmount,
        );
    }

    private function getTenPoundMatchedDonation(FundType $fundType): Donation
    {
        $donationAmount = '10';
        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: $donationAmount,
            projectId: 'projectid012345678',
            psp: 'stripe',
        ), $this->getMinimalCampaign(), PersonId::nil());
        $donation->setSalesforceId('sf_1244');
        $donation->setTransactionId('pi_tenPound123');

        $fund = new Fund(currencyCode: 'GBP', name: '', slug: null, salesforceId: null, fundType: $fundType);
        $campaignFunding = new CampaignFunding(
            fund: $fund,
            amount: $donationAmount,
            amountAvailable: '0',
        );

        $championFundWithdrawal = new FundingWithdrawal($campaignFunding);
        $championFundWithdrawal->setAmount($donationAmount);
        $donation->addFundingWithdrawal($championFundWithdrawal);

        return $donation;
    }

    /**
     * @param ObjectProphecy<Allocator> $allocatorProphecy
     * @param ObjectProphecy<CampaignFundingRepository> $campaignFundingRepoProphecy
     * @param ObjectProphecy<DonationRepository> $donationRepoProphecy
     * @param ObjectProphecy<LoggerInterface> $loggerProphecy
     * @return RedistributeMatchFunds
     */
    private function getCommand(
        ObjectProphecy $allocatorProphecy,
        ObjectProphecy $campaignFundingRepoProphecy,
        \DateTimeImmutable $now,
        ObjectProphecy $donationRepoProphecy,
        ObjectProphecy $loggerProphecy,
    ): RedistributeMatchFunds {
        $command = new RedistributeMatchFunds(
            matchFundsRedistributor: new MatchFundsRedistributor(
                allocator: $allocatorProphecy->reveal(),
                chatter: $this->createStub(ChatterInterface::class),
                donationRepository: $donationRepoProphecy->reveal(),
                now: $now,
                campaignFundingRepository: $campaignFundingRepoProphecy->reveal(),
                logger: $loggerProphecy->reveal(),
                entityManager: $this->createStub(EntityManagerInterface::class),
                bus: $this->messageBusProphecy->reveal(),
            ),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        return $command;
    }
}
