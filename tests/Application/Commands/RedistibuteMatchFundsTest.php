<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use MatchBot\Application\Commands\RedistributeMatchFunds;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\Pledge;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
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

    public function testOneDonationHasChampFundsUsedAndCouldBeAssignedPledge(): void
    {
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findWithMatchingWhichCouldBeReplacedWithHigherPriorityAllocation(Argument::type(\DateTimeImmutable::class))->willReturn([
            Donation::fromApiModel(new DonationCreate(
                currencyCode: 'GBP',
                donationAmount: '10',
                projectId: 'project-id',
                psp: 'stripe',
            ), $this->getMinimalCampaign()),
        ]);
        $donationRepoProphecy->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldBeCalledOnce();
        $donationRepoProphecy->allocateMatchFunds(Argument::type(Donation::class))
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

        $commandTester = new CommandTester($this->getCommand($campaignFundingRepoProphecy, $donationRepoProphecy));
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
     * @return RedistributeMatchFunds
     */
    private function getCommand(
        ObjectProphecy $campaignFundingRepository,
        ObjectProphecy $donationRepoProphecy,
    ): RedistributeMatchFunds {
        $command = new RedistributeMatchFunds($campaignFundingRepository->reveal(), $donationRepoProphecy->reveal());
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        return $command;
    }
}
