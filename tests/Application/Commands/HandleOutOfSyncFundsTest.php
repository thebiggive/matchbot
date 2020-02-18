<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use MatchBot\Application\Commands\HandleOutOfSyncFunds;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\FundingWithdrawalRepository;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

class HandleOutOfSyncFundsTest extends TestCase
{
    public function testCheck(): void
    {
        $command = new HandleOutOfSyncFunds(
            $this->getCampaignFundingRepoPropechy()->reveal(),
            $this->getFundingWithdrawalRepoProphecy()->reveal(),
            $this->getMatchingAdapterProphecy(false)->reveal(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));

        $commandTester = new CommandTester($command);
        $commandTester->execute(['mode' => 'check']);

        $expectedOutputLines = [
            'matchbot:handle-out-of-sync-funds starting!',
            'Funding 2 is over-matched by 10.00. Donation withdrawals 51.00, funding allocations 41.00',
            'Funding 3 is under-matched by 30.00. Donation withdrawals 500.00, funding allocations 530.00',
            'Checked 3 fundings. Found 1 with correct allocations, 1 over-matched and 1 under-matched',
            'matchbot:handle-out-of-sync-funds complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testFix(): void
    {
        $command = new HandleOutOfSyncFunds(
            $this->getCampaignFundingRepoPropechy()->reveal(),
            $this->getFundingWithdrawalRepoProphecy()->reveal(),
            $this->getMatchingAdapterProphecy(true)->reveal(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));

        $commandTester = new CommandTester($command);
        $commandTester->execute(['mode' => 'fix']);

        $expectedOutputLines = [
            'matchbot:handle-out-of-sync-funds starting!',
            'Funding 2 is over-matched by 10.00. Donation withdrawals 51.00, funding allocations 41.00',
            'Funding 3 is under-matched by 30.00. Donation withdrawals 500.00, funding allocations 530.00',
            'Released 30.00 to funding ID 3',
            'New fund total for funding ID 3: 487.65',
            'Checked 3 fundings. Found 1 with correct allocations, 1 over-matched and 1 under-matched',
            'matchbot:handle-out-of-sync-funds complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testWithModeMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments (missing: "mode").');

        $command = new HandleOutOfSyncFunds(
            $this->prophesize(CampaignFundingRepository::class)->reveal(),
            $this->prophesize(FundingWithdrawalRepository::class)->reveal(),
            $this->prophesize(Adapter::class)->reveal(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);
    }

    public function testWithModeInvalid(): void
    {
        $command = new HandleOutOfSyncFunds(
            $this->prophesize(CampaignFundingRepository::class)->reveal(),
            $this->prophesize(FundingWithdrawalRepository::class)->reveal(),
            $this->prophesize(Adapter::class)->reveal(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));

        $commandTester = new CommandTester($command);
        $commandTester->execute(['mode' => 'fixx']);

        $expectedOutputLines = [
            'matchbot:handle-out-of-sync-funds starting!',
            'Please set the mode to "check" or "fix"',
            'matchbot:handle-out-of-sync-funds complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(1, $commandTester->getStatusCode());
    }

    /**
     * Funding + withdrawal repo propechies are designed to have 1 fund in sync, 1 over-matched and 1 under-matched.
     * @see getFundingWithdrawalRepoProphecy()
     * @return ObjectProphecy|CampaignFundingRepository
     */
    private function getCampaignFundingRepoPropechy()
    {
        $campaignFundingRepoProphecy = $this->prophesize(CampaignFundingRepository::class);
        $campaignFundingRepoProphecy->findAll()
            ->willReturn([
                $this->getFundingInSync(),
                $this->getFundingOverMatched(),
                $this->getFundingUnderMatched(),
            ])
            ->shouldBeCalledOnce();

        return $campaignFundingRepoProphecy;
    }

    /**
     * Funding + withdrawal repo propechies are designed to have 1 fund in sync, 1 over-matched and 1 under-matched.
     * For the purpose of this test, we assume the DB is self-consistent (which is why this repo has values always
     * matching those on the CampaignFunding test objects) but that the matching adapter may have got out of sync
     * somehow. This is the main scenario the one-off fix case is intended to deal with.
     *
     * @see getCampaignFundingRepoPropechy()
     * @return ObjectProphecy|FundingWithdrawalRepository
     */
    private function getFundingWithdrawalRepoProphecy()
    {
        $fundingWithdrawalRepoProphecy = $this->prophesize(FundingWithdrawalRepository::class);
        $fundingWithdrawalRepoProphecy->getWithdrawalsTotal($this->getFundingInSync())
            ->willReturn(bcsub(
                $this->getFundingInSync()->getAmount(),
                $this->getFundingInSync()->getAmountAvailable(),
                2
            ))
            ->shouldBeCalledOnce();
        $fundingWithdrawalRepoProphecy->getWithdrawalsTotal($this->getFundingOverMatched())
            ->willReturn(bcsub(
                $this->getFundingOverMatched()->getAmount(),
                $this->getFundingOverMatched()->getAmountAvailable(),
                2
            ))
            ->shouldBeCalledOnce();
        $fundingWithdrawalRepoProphecy->getWithdrawalsTotal($this->getFundingUnderMatched())
            ->willReturn(bcsub(
                $this->getFundingUnderMatched()->getAmount(),
                $this->getFundingUnderMatched()->getAmountAvailable(),
                2
            ))
            ->shouldBeCalledOnce();

        return $fundingWithdrawalRepoProphecy;
    }

    private function getMatchingAdapterProphecy($expectFixes = false)
    {
        $matchingAdapterProphecy = $this->prophesize(Adapter::class);
        $matchingAdapterProphecy
            ->getAmountAvailable($this->getFundingInSync())
            ->willReturn('80.01') // DB amount available === £80.01
            ->shouldBeCalledOnce();
        $matchingAdapterProphecy
            ->getAmountAvailable($this->getFundingOverMatched())
            ->willReturn('109.00') // DB amount available === £99.00
            ->shouldBeCalledOnce();
        $matchingAdapterProphecy
            ->getAmountAvailable($this->getFundingUnderMatched())
            ->willReturn('457.65') // DB amount available === £487.65
            ->shouldBeCalledOnce();

        if ($expectFixes) {
            $matchingAdapterProphecy->runTransactionally(Argument::type('callable'))
                ->willReturn('487.65') // Amount available after adjustment
                ->shouldBeCalledOnce();
        } else {
            $matchingAdapterProphecy->runTransactionally(Argument::type('callable'))->shouldNotBeCalled();
        }

        return $matchingAdapterProphecy;
    }

    private function getFundingInSync(): CampaignFunding
    {
        $fundingInSync = new CampaignFunding();
        $fundingInSync->setId(1);
        $fundingInSync->setAmount('123.45');
        $fundingInSync->setAmountAvailable('80.01');

        return $fundingInSync;
    }

    private function getFundingOverMatched(): CampaignFunding
    {
        $fundingOverMatched = new CampaignFunding();
        $fundingOverMatched->setId(2);
        $fundingOverMatched->setAmount('150');
        $fundingOverMatched->setAmountAvailable('99.00');

        return $fundingOverMatched;
    }

    private function getFundingUnderMatched(): CampaignFunding
    {
        $fundingUnderMatched = new CampaignFunding();
        $fundingUnderMatched->setId(3);
        $fundingUnderMatched->setAmount('987.65');
        $fundingUnderMatched->setAmountAvailable('487.65');

        return $fundingUnderMatched;
    }
}
