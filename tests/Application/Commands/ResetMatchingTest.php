<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use Doctrine\DBAL\Driver\PDOException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use MatchBot\Application\Commands\ResetMatching;
use MatchBot\Application\Matching;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\ChampionFund;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

class ResetMatchingTest extends TestCase
{
    use ProphecyTrait;

    public function testSinglePush(): void
    {
        $fund = new ChampionFund();
        $fund->setSalesforceId('sfFundId123');
        $fund->setSalesforceLastPull(new \DateTime());
        $fund->setAmount('400');
        $fund->setName('Test Champion Fund 123');

        $campaignFunding = new CampaignFunding();
        $campaignFunding->setFund($fund);
        $campaignFunding->setCurrencyCode('GBP');
        $campaignFunding->setAmount('400');
        $campaignFunding->setAllocationOrder(200);

        $matchingAdapterProphecy = $this->prophesize(Matching\Adapter::class);
        $matchingAdapterProphecy->delete($campaignFunding)->shouldBeCalledOnce();

        $campaignFundingRepoProphecy = $this->prophesize(CampaignFundingRepository::class);
        $campaignFundingRepoProphecy->findAll()
            ->willReturn([$campaignFunding])
            ->shouldBeCalledOnce();

        $command = new ResetMatching($campaignFundingRepoProphecy->reveal(), $matchingAdapterProphecy->reveal());
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:reset-matching starting!',
            'Completed matching reset for 1 fundings.',
            'matchbot:reset-matching complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testBlankDb(): void
    {
        $matchingAdapterProphecy = $this->prophesize(Matching\Adapter::class);
        $matchingAdapterProphecy->delete(Argument::type(CampaignFunding::class))->shouldNotBeCalled();

        $campaignFundingRepoProphecy = $this->prophesize(CampaignFundingRepository::class);
        $campaignFundingRepoProphecy->findAll()
            ->willThrow(new TableNotFoundException(
                'An exception occurred.. (unit test sample message)',
                // Doctrine PDOException (a DriverException subclass) wraps native \PDOException.
                new PDOException(
                    new \PDOException(
                        'SQLSTATE[42S02]: Base table or view not found: 1146 ' .
                        "Table 'matchbot.CampaignFunding' doesn't exist"
                    )
                )
            ))
            ->shouldBeCalledOnce();

        $command = new ResetMatching($campaignFundingRepoProphecy->reveal(), $matchingAdapterProphecy->reveal());
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:reset-matching starting!',
            'Skipping matching reset as database is empty.',
            'matchbot:reset-matching complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }
}
