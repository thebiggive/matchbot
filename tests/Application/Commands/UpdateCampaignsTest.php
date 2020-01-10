<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use MatchBot\Application\Commands\UpdateCampaigns;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\FundRepository;
use MatchBot\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

class UpdateCampaignsTest extends TestCase
{
    public function testSingleUpdate(): void
    {
        $campaign = new Campaign();
        $campaign->setSalesforceId('someCampaignId');
        $campaignRepoPropehcy = $this->prophesize(CampaignRepository::class);
        $campaignRepoPropehcy->findAll()
            ->willReturn([$campaign])
            ->shouldBeCalledOnce();
        $campaignRepoPropehcy->pull($campaign)->willReturn($campaign)->shouldBeCalledOnce();

        $fundRepoProphecy = $this->prophesize(FundRepository::class);
        $fundRepoProphecy->pullForCampaign($campaign)->shouldbeCalledOnce();

        $command = new UpdateCampaigns($campaignRepoPropehcy->reveal(), $fundRepoProphecy->reveal());
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:update-campaigns starting!',
            'Updated campaign someCampaignId',
            'matchbot:update-campaigns complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
    }
}
