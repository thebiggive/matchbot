<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Commands\RedistributeMatchFunds;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching\MatchFundsRedistributor;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\ChampionFund;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\Pledge;
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

    protected function setUp(): void
    {
        parent::setUp();
        $this->newYearsEveNoon = new \DateTimeImmutable('2023-12-31T12:00:00');
        $this->earlyNovemberNoon = new \DateTimeImmutable('2023-11-05T12:00:00');

        $this->messageBusProphecy = $this->prophesize(RoutableMessageBus::class);
    }

    public function testNoEligibleDonations(): void
    {
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findWithMatchingWhichCouldBeReplacedWithHigherPriorityAllocation(
            $this->newYearsEveNoon,
            $this->earlyNovemberNoon,
        )
            ->willReturn([])
            ->shouldBeCalledOnce();
        $donationRepoProphecy->releaseMatchFunds(Argument::type(Donation::class))->shouldNotBeCalled();

        $this->messageBusProphecy->dispatch(Argument::type(Envelope::class))->shouldNotBeCalled();

        $commandTester = new CommandTester($this->getCommand(
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
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testOneDonationHasChampFundsUsedAndIsAssignedPledgeToFullMatchedValue(): void
    {
        $donation = $this->getTenPoundChampionMatchedDonation();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findWithMatchingWhichCouldBeReplacedWithHigherPriorityAllocation(
            $this->newYearsEveNoon,
            $this->earlyNovemberNoon,
        )->willReturn([$donation]);

        $donationRepoProphecy->releaseMatchFunds($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy->allocateMatchFunds($donation)
            ->shouldBeCalledOnce()
            ->willReturn('10.00');

        $loggerProphecy = $this->prophesize(LoggerInterface::class);
        $loggerProphecy->error(Argument::type('string'))->shouldNotBeCalled();

        $this->messageBusProphecy->dispatch(Argument::type(Envelope::class))->shouldBeCalledOnce()->willReturnArgument();

        $campaignFundingRepoProphecy = $this->prophesize(CampaignFundingRepository::class);
        $campaignFundingRepoProphecy->getAvailableFundings(Argument::type(Campaign::class))
            ->willReturn([$this->getFullyAvailablePledgeFunding()]);

        $commandTester = new CommandTester($this->getCommand(
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
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
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
        $donation = $this->getTenPoundChampionMatchedDonation();

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findWithMatchingWhichCouldBeReplacedWithHigherPriorityAllocation(
            $this->newYearsEveNoon,
            $this->earlyNovemberNoon,
        )->willReturn([$donation]);

        $donationRepoProphecy->releaseMatchFunds($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy->allocateMatchFunds($donation)
            ->shouldBeCalledOnce()
            ->willReturn('5.00'); // Half the donation matched after redistribution.

        $uuid = $donation->getUuid();
        $loggerProphecy = $this->prophesize(LoggerInterface::class);
        $loggerProphecy->error("Donation $uuid had redistributed match funds reduced from 10.00 to 5.00 (GBP)")
            ->shouldBeCalledOnce();

        $this->messageBusProphecy->dispatch(Argument::type(Envelope::class))->shouldBeCalledOnce()->willReturnArgument();

        $campaignFundingRepoProphecy = $this->prophesize(CampaignFundingRepository::class);
        $campaignFundingRepoProphecy->getAvailableFundings(Argument::type(Campaign::class))
            ->willReturn([$this->getFullyAvailablePledgeFunding()]);

        $commandTester = new CommandTester($this->getCommand(
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
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    private function getFullyAvailablePledgeFunding(): CampaignFunding
    {
        $pledgeAmount = '101.00';
        $pledge = new Pledge(currencyCode: 'GBP', name: '', salesforceId: null);

        return new CampaignFunding(
            fund: $pledge,
            amount: $pledgeAmount,
            amountAvailable: $pledgeAmount,
            allocationOrder: 100,
        );
    }

    private function getTenPoundChampionMatchedDonation(): Donation
    {
        $donationAmount = '10';
        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: $donationAmount,
            projectId: 'projectid012345678',
            psp: 'stripe',
        ), $this->getMinimalCampaign());
        $donation->setSalesforceId('sf_1244');
        $donation->setTransactionId('pi_tenPound123');

        $championFund = new ChampionFund(currencyCode: 'GBP', name: '', salesforceId: null);
        $campaignFunding = new CampaignFunding(
            fund: $championFund,
            amount: $donationAmount,
            amountAvailable: '0',
            allocationOrder: 200,
        );

        $championFundWithdrawal = new FundingWithdrawal($campaignFunding);
        $championFundWithdrawal->setAmount($donationAmount);
        $donation->addFundingWithdrawal($championFundWithdrawal);

        return $donation;
    }

    /**
     * @param ObjectProphecy<CampaignFundingRepository> $campaignFundingRepository
     * @param ObjectProphecy<DonationRepository> $donationRepoProphecy
     * @param ObjectProphecy<LoggerInterface> $loggerProphecy
     * @return RedistributeMatchFunds
     */
    private function getCommand(
        ObjectProphecy $campaignFundingRepository,
        \DateTimeImmutable $now,
        ObjectProphecy $donationRepoProphecy,
        ObjectProphecy $loggerProphecy,
    ): RedistributeMatchFunds {
        $command = new RedistributeMatchFunds(
            matchFundsRedistributor: new MatchFundsRedistributor(
                chatter: $this->createStub(ChatterInterface::class),
                donationRepository: $donationRepoProphecy->reveal(),
                now: $now,
                campaignFundingRepository: $campaignFundingRepository->reveal(),
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
