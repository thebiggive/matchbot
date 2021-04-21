<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Commands\UpdateCampaigns;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\FundRepository;
use Prophecy\PhpUnit\ProphecyTrait;
use MatchBot\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

class UpdateCampaignsTest extends TestCase
{
    use ProphecyTrait;

    public function testSingleUpdateSuccess(): void
    {
        $campaign = new Campaign();
        $campaign->setSalesforceId('someCampaignId');
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->findRecentAndLive()
            ->willReturn([$campaign])
            ->shouldBeCalledOnce();
        $campaignRepoProphecy->pull($campaign)->willReturn($campaign)->shouldBeCalledOnce();

        $fundRepoProphecy = $this->prophesize(FundRepository::class);
        $fundRepoProphecy->pullForCampaign($campaign)->shouldbeCalledOnce();

        $command = new UpdateCampaigns(
            $campaignRepoProphecy->reveal(),
            $this->getAppInstance()->getContainer()->get(EntityManagerInterface::class),
            $fundRepoProphecy->reveal(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:update-campaigns starting!',
            'Updated campaign someCampaignId',
            'matchbot:update-campaigns complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testSingleUpdateNotFoundOnSalesforceOutsideProduction(): void
    {
        // This case should be skipped over without crashing, in non-production envs.

        $campaign = new Campaign();
        $campaign->setSalesforceId('missingOnSfCampaignId');
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->findRecentAndLive()
            ->willReturn([$campaign])
            ->shouldBeCalledOnce();
        $campaignRepoProphecy->pull($campaign)->willThrow(NotFoundException::class)->shouldBeCalledOnce();

        $fundRepoProphecy = $this->prophesize(FundRepository::class);
        $fundRepoProphecy->pullForCampaign($campaign)->shouldNotBeCalled(); // Exception reached before this call

        $command = new UpdateCampaigns(
            $campaignRepoProphecy->reveal(),
            $this->getAppInstance()->getContainer()->get(EntityManagerInterface::class),
            $fundRepoProphecy->reveal(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:update-campaigns starting!',
            'Skipping unknown sandbox campaign missingOnSfCampaignId',
            'matchbot:update-campaigns complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testSingleUpdateSuccessWithAllOption(): void
    {
        $campaign = new Campaign();
        $campaign->setSalesforceId('someCampaignId');
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->findAll()
            ->willReturn([$campaign])
            ->shouldBeCalledOnce();
        $campaignRepoProphecy->pull($campaign)->willReturn($campaign)->shouldBeCalledOnce();

        $fundRepoProphecy = $this->prophesize(FundRepository::class);
        $fundRepoProphecy->pullForCampaign($campaign)->shouldbeCalledOnce();

        $command = new UpdateCampaigns(
            $campaignRepoProphecy->reveal(),
            $this->getAppInstance()->getContainer()->get(EntityManagerInterface::class),
            $fundRepoProphecy->reveal(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));

        $commandTester = new CommandTester($command);
        $commandTester->execute(['--all' => null]);

        $expectedOutputLines = [
            'matchbot:update-campaigns starting!',
            'Updated campaign someCampaignId',
            'matchbot:update-campaigns complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }
}
