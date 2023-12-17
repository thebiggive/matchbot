<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use MatchBot\Application\Commands\RedistributeMatchFunds;
use MatchBot\Application\HttpModels\DonationCreate;
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

class RedistibuteMatchFundsTest extends TestCase
{
    public function testNoEligibleDonations(): void
    {
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findWithMatchingWhichCouldBeReplacedWithHigherPriorityAllocation(
            Argument::type(\DateTimeImmutable::class)
        )
            ->willReturn([])
            ->shouldBeCalledOnce();
        $donationRepoProphecy->releaseMatchFunds(Argument::type(Donation::class))->shouldNotBeCalled();

        $commandTester = new CommandTester($this->getCommand(
            $this->prophesize(CampaignFundingRepository::class),
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
        $donationAmount = '10';
        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: $donationAmount,
            projectId: 'project-id',
            psp: 'stripe',
        ), $this->getMinimalCampaign());

        $championFund = new ChampionFund();
        $championFund->setAmount($donationAmount);
        $campaignFunding = new CampaignFunding();
        $campaignFunding->setAmount($donationAmount);
        // We're bypassing normal allocation helpers and will set up the FundingWithdrawal to the test donation below.
        $campaignFunding->setAmountAvailable('0');
        $campaignFunding->setAllocationOrder(200);
        $campaignFunding->setFund($championFund);

        $championFundWithdrawal = new FundingWithdrawal($campaignFunding);
        $championFundWithdrawal->setAmount($donationAmount);
        $donation->addFundingWithdrawal($championFundWithdrawal);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findWithMatchingWhichCouldBeReplacedWithHigherPriorityAllocation(
            Argument::type(\DateTimeImmutable::class)
        )->willReturn([$donation]);

        $donationRepoProphecy->releaseMatchFunds($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy->allocateMatchFunds($donation)
            ->shouldBeCalledOnce()
            ->willReturn('10.00');
        $donationRepoProphecy->push($donation, false)
            ->shouldBeCalledOnce();

        $loggerProphecy = $this->prophesize(LoggerInterface::class);
        $loggerProphecy->error(Argument::type('string'))->shouldNotBeCalled();

        $pledgeAmount = '101.00';
        $pledge = new Pledge();
        $pledge->setAmount($pledgeAmount);
        $pledgeFunding = new CampaignFunding();
        $pledgeFunding->setAmount($pledgeAmount);
        $pledgeFunding->setAmountAvailable($pledgeAmount);
        $pledgeFunding->setAllocationOrder(100);
        $pledgeFunding->setFund($pledge);

        $campaignFundingRepoProphecy = $this->prophesize(CampaignFundingRepository::class);
        $campaignFundingRepoProphecy->getAvailableFundings(Argument::type(Campaign::class))
            ->willReturn([$pledgeFunding]);

        $commandTester = new CommandTester($this->getCommand(
            $campaignFundingRepoProphecy,
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
        $donationAmount = '10';
        $donation = Donation::fromApiModel(new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: $donationAmount,
            projectId: 'project-id',
            psp: 'stripe',
        ), $this->getMinimalCampaign());

        $championFund = new ChampionFund();
        $championFund->setAmount($donationAmount);
        $campaignFunding = new CampaignFunding();
        $campaignFunding->setAmount($donationAmount);
        // We're bypassing normal allocation helpers and will set up the FundingWithdrawal to the test donation below.
        $campaignFunding->setAmountAvailable('0');
        $campaignFunding->setAllocationOrder(200);
        $campaignFunding->setFund($championFund);

        $championFundWithdrawal = new FundingWithdrawal($campaignFunding);
        $championFundWithdrawal->setAmount($donationAmount);
        $donation->addFundingWithdrawal($championFundWithdrawal);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findWithMatchingWhichCouldBeReplacedWithHigherPriorityAllocation(
            Argument::type(\DateTimeImmutable::class)
        )->willReturn([$donation]);

        $donationRepoProphecy->releaseMatchFunds($donation)
            ->shouldBeCalledOnce();
        $donationRepoProphecy->allocateMatchFunds($donation)
            ->shouldBeCalledOnce()
            ->willReturn('5.00'); // Half the donation matched after redistribution.
        $donationRepoProphecy->push($donation, false)
            ->shouldBeCalledOnce();

        $uuid = $donation->getUuid();
        $loggerProphecy = $this->prophesize(LoggerInterface::class);
        $loggerProphecy->error("Donation $uuid had redistributed match funds reduced from 10.00 to 5.00 (GBP)")
            ->shouldBeCalledOnce();

        $pledgeAmount = '101.00';
        $pledge = new Pledge();
        $pledge->setAmount($pledgeAmount);
        $pledgeFunding = new CampaignFunding();
        $pledgeFunding->setAmount($pledgeAmount);
        $pledgeFunding->setAmountAvailable($pledgeAmount);
        $pledgeFunding->setAllocationOrder(100);
        $pledgeFunding->setFund($pledge);

        $campaignFundingRepoProphecy = $this->prophesize(CampaignFundingRepository::class);
        $campaignFundingRepoProphecy->getAvailableFundings(Argument::type(Campaign::class))
            ->willReturn([$pledgeFunding]);

        $commandTester = new CommandTester($this->getCommand(
            $campaignFundingRepoProphecy,
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
     * @param ObjectProphecy<CampaignFundingRepository> $campaignFundingRepository
     * @param ObjectProphecy<DonationRepository> $donationRepoProphecy
     * @param ObjectProphecy<LoggerInterface> $loggerProphecy
     * @return RedistributeMatchFunds
     */
    private function getCommand(
        ObjectProphecy $campaignFundingRepository,
        ObjectProphecy $donationRepoProphecy,
        ObjectProphecy $loggerProphecy,
    ): RedistributeMatchFunds {
        $command = new RedistributeMatchFunds(
            $campaignFundingRepository->reveal(),
            $donationRepoProphecy->reveal(),
            $loggerProphecy->reveal(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        return $command;
    }
}
