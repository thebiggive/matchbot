<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use Doctrine\DBAL\Driver\PDO\Exception as PDOException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\DBAL\Query;
use MatchBot\Application\Commands\ResetMatching;
use MatchBot\Application\Matching;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\ChampionFund;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

class ResetMatchingTest extends TestCase
{
    public function testSinglePush(): void
    {
        $fund = new ChampionFund();
        $fund->setSalesforceId('sfFundId123');
        $fund->setSalesforceLastPull(new \DateTime());
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
        $command->setLogger(new NullLogger());

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

        /**
         * @psalm-suppress InternalMethod - use in test to simulate failure is not a big issue. We'll
         * fix if/when the test errors.
         * @psalm-suppress InternalClass
         */
        $campaignFundingRepoProphecy->findAll()
            ->willThrow(
                new TableNotFoundException(
                // Doctrine PDO\Exception (a DriverException subclass) wraps native \PDOException.
                    PDOException::new(
                        new \PDOException(
                            'SQLSTATE[42S02]: Base table or view not found: 1146 ' .
                            "Table 'matchbot.CampaignFunding' doesn't exist"
                        )
                    ),
                    new Query('SELECT test...', [], []),
                )
            )
            ->shouldBeCalledOnce();

        $command = new ResetMatching($campaignFundingRepoProphecy->reveal(), $matchingAdapterProphecy->reveal());
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

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
