<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use MatchBot\Application\Commands\ResetMatching;
use MatchBot\Application\Matching;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\ChampionFund;
use MatchBot\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

class ResetMatchingTest extends TestCase
{
    public function testSinglePush(): void
    {
        $fund = new ChampionFund();
        $fund->setSalesforceId('sfFundId123');
        $fund->setSalesforceLastPull(new \DateTime());
        $fund->setAmount('400');
        $fund->setName( 'Test Champion Fund 123');

        $campaignFunding = new CampaignFunding();
        $campaignFunding->setFund($fund);
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
            'matchbot:reset-matching complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }
}
