<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Commands\HandleOutOfSyncFunds;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\FundingWithdrawalRepository;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundType;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

class HandleOutOfSyncFundsTest extends TestCase
{
    private CampaignFunding $fundingUnderMatched;
    private CampaignFunding $fundingInSync;
    private CampaignFunding $fundingOverMatched;
    private CampaignFunding $fundingUnderMatchedWithNothingAllocated;

    #[\Override]
    public function setUp(): void
    {
        $this->fundingUnderMatched = $this->getFundingUnderMatched();
        $this->fundingInSync = $this->getFundingInSync();
        $this->fundingOverMatched = $this->getFundingOverMatched();
        $this->fundingUnderMatchedWithNothingAllocated = $this->getFundingUnderMatchedWithNothingAllocated();
    }
    public function testCheck(): void
    {
        $command = $this->getCommand(expectFixes: false);
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
        $commandTester->execute(['mode' => 'check']);

        $expectedOutputLines = [
            'matchbot:handle-out-of-sync-funds starting!',
            'Funding 2 is over-matched by 10.00. Donation withdrawals 51.00, funding allocations 41.00',
            'Funding 3 is under-matched by 30.00. Donation withdrawals 500.00, funding allocations 530.00',
            'Funding 4 is under-matched by 0.01. Donation withdrawals 0.00, funding allocations 0.01',
            'Checked 4 fundings. Found 1 with correct allocations, 1 over-matched and 2 under-matched',
            'matchbot:handle-out-of-sync-funds complete!',
        ];
        $this->assertSame(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testFix(): void
    {
        $command = $this->getCommand(expectFixes: true);
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
        $commandTester->execute(['mode' => 'fix']);

        $expectedOutputLines = [
            'matchbot:handle-out-of-sync-funds starting!',
            'Funding 2 is over-matched by 10.00. Donation withdrawals 51.00, funding allocations 41.00',
            'Funding 3 is under-matched by 30.00. Donation withdrawals 500.00, funding allocations 530.00',
            'Released 30.00 to funding ID 3',
            'New fund total for funding ID 3: 487.65',
            'Funding 4 is under-matched by 0.01. Donation withdrawals 0.00, funding allocations 0.01',
            'Released 0.01 to funding ID 4',
            'New fund total for funding ID 4: 1000.00',
            'Checked 4 fundings. Found 1 with correct allocations, 1 over-matched and 2 under-matched',
            'matchbot:handle-out-of-sync-funds complete!',
        ];
        $this->assertSame(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testWithModeMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not enough arguments (missing: "mode").');

        $command = $this->getCommand(expectFixes: false, noopAdapter: true);
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);
    }

    public function testWithModeInvalid(): void
    {
        $command = $this->getCommand(expectFixes: false, noopAdapter: true);

        $commandTester = new CommandTester($command);
        $commandTester->execute(['mode' => 'fixx']);

        $expectedOutputLines = [
            'matchbot:handle-out-of-sync-funds starting!',
            'Please set the mode to "check" or "fix"',
            'matchbot:handle-out-of-sync-funds complete!',
        ];
        $this->assertSame(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertSame(1, $commandTester->getStatusCode());
    }

    /**
     * Funding + withdrawal repo propechies are designed to have 1 fund in sync, 1 over-matched and 1 under-matched.
     * @see getFundingWithdrawalRepoProphecy()
     * @return ObjectProphecy<CampaignFundingRepository>
     */
    private function getCampaignFundingRepoPropechy()
    {
        $campaignFundingRepoProphecy = $this->prophesize(CampaignFundingRepository::class);
        $campaignFundingRepoProphecy->findAll()
            ->willReturn([
                $this->fundingInSync,
                $this->fundingOverMatched,
                $this->fundingUnderMatched,
                $this->fundingUnderMatchedWithNothingAllocated,
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
     * @return ObjectProphecy<FundingWithdrawalRepository>
     */
    private function getFundingWithdrawalRepoProphecy()
    {
        $fundingWithdrawalRepoProphecy = $this->prophesize(FundingWithdrawalRepository::class);
        $fundingWithdrawalRepoProphecy->getWithdrawalsTotal($this->fundingInSync)
            ->willReturn(bcsub(
                $this->fundingInSync->getAmount(),
                $this->fundingInSync->getAmountAvailable(),
                2
            ))
            ->shouldBeCalledOnce();
        $fundingWithdrawalRepoProphecy->getWithdrawalsTotal($this->fundingOverMatched)
            ->willReturn(bcsub(
                $this->fundingOverMatched->getAmount(),
                $this->fundingOverMatched->getAmountAvailable(),
                2
            ))
            ->shouldBeCalledOnce();
        $fundingWithdrawalRepoProphecy->getWithdrawalsTotal($this->fundingUnderMatched)
            ->willReturn(bcsub(
                $this->fundingUnderMatched->getAmount(),
                $this->fundingUnderMatched->getAmountAvailable(),
                2
            ))
            ->shouldBeCalledOnce();
        $fundingWithdrawalRepoProphecy->getWithdrawalsTotal($this->fundingUnderMatchedWithNothingAllocated)
            ->willReturn(bcsub(
                $this->fundingUnderMatchedWithNothingAllocated->getAmount(),
                $this->fundingUnderMatchedWithNothingAllocated->getAmountAvailable(),
                2
            ))
            ->shouldBeCalledOnce();

        return $fundingWithdrawalRepoProphecy;
    }

    /**
     * @psalm-return ObjectProphecy<Adapter>
     */
    private function getMatchingAdapterProphecy(bool $expectFixes = false): ObjectProphecy
    {
        $matchingAdapterProphecy = $this->prophesize(Adapter::class);
        $matchingAdapterProphecy
            ->getAmountAvailable($this->fundingInSync)
            ->willReturn('80.01') // DB amount available === £80.01
            ->shouldBeCalledOnce();
        $matchingAdapterProphecy
            ->getAmountAvailable($this->fundingOverMatched)
            ->willReturn('109.00') // DB amount available === £99.00
            ->shouldBeCalledOnce();
        $matchingAdapterProphecy
            ->getAmountAvailable($this->fundingUnderMatched)
            ->willReturn('457.65') // DB amount available === £487.65
            ->shouldBeCalledOnce();
        $matchingAdapterProphecy
            ->getAmountAvailable($this->fundingUnderMatchedWithNothingAllocated)
            ->willReturn('999.99') // DB amount available === £1000.00
            ->shouldBeCalledOnce();

        if ($expectFixes) {
            $matchingAdapterProphecy->addAmount(Argument::cetera())
                ->willReturn('487.65', '1000.00') // Amount available after adjustment, in call order.
                ->shouldBeCalledTimes(2);
        } else {
            $matchingAdapterProphecy->addAmount(Argument::cetera())->shouldNotBeCalled();
        }

        return $matchingAdapterProphecy;
    }

    private function getFundingInSync(): CampaignFunding
    {
        $fundingInSync = new CampaignFunding(
            fund: new Fund('GBP', 'some pledge', null, null, fundType: FundType::Pledge),
            amount: '123.45',
            amountAvailable: '80.01',
        );
        $fundingInSync->setId(1);

        return $fundingInSync;
    }

    private function getFundingOverMatched(): CampaignFunding
    {
        $fundingOverMatched = new CampaignFunding(
            fund: new Fund('GBP', 'some pledge', null, null, fundType: FundType::Pledge),
            amount: '150',
            amountAvailable: '99.0',
        );

        $fundingOverMatched->setId(2);

        return $fundingOverMatched;
    }

    private function getFundingUnderMatched(): CampaignFunding
    {
        $fundingUnderMatched = new CampaignFunding(
            fund: new Fund('GBP', 'some pledge', null, null, fundType: FundType::Pledge),
            amount: '987.65',
            amountAvailable: '487.65',
        );
        $fundingUnderMatched->setId(3);

        return $fundingUnderMatched;
    }

    private function getFundingUnderMatchedWithNothingAllocated(): CampaignFunding
    {
        $fundingUnderMatchedWithZero = new CampaignFunding(
            fund: new Fund('GBP', 'some pledge', null, null, fundType: FundType::Pledge),
            amount: '1000.00',
            amountAvailable: '1000.00',
        );
        $fundingUnderMatchedWithZero->setId(4);

        return $fundingUnderMatchedWithZero;
    }

    private function getCommand(bool $expectFixes, bool $noopAdapter = false): HandleOutOfSyncFunds
    {
        $adapter = $noopAdapter
            ? $this->prophesize(Adapter::class)->reveal()
            : $this->getMatchingAdapterProphecy($expectFixes)->reveal();

        $campaignRepo = $noopAdapter
            ? $this->prophesize(CampaignFundingRepository::class)->reveal()
            : $this->getCampaignFundingRepoPropechy()->reveal();

        $withdrawalRepo = $noopAdapter
            ? $this->prophesize(FundingWithdrawalRepository::class)->reveal()
            : $this->getFundingWithdrawalRepoProphecy()->reveal();

        $command = new HandleOutOfSyncFunds(
            campaignFundingRepository: $campaignRepo,
            entityManager: $this->createStub(EntityManagerInterface::class),
            fundingWithdrawalRepository: $withdrawalRepo,
            matchingAdapter: $adapter,
            donationRepository: $this->createStub(DonationRepository::class),
            logger: $this->createStub(LoggerInterface::class),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        return $command;
    }
}
