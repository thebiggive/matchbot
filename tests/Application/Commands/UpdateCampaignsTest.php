<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use MatchBot\Application\Commands\UpdateCampaigns;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\FundRepository;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

class UpdateCampaignsTest extends TestCase
{
    public function testSingleUpdateSuccess(): void
    {
        $campaign = new Campaign(Salesforce18Id::ofCampaign('someCampaignIdxxxx'), charity: null);
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->findRecentLiveAndPendingGiftAidApproval()
            ->willReturn([$campaign])
            ->shouldBeCalledOnce();
        $campaignRepoProphecy->updateFromSf($campaign)->shouldBeCalledOnce();

        $fundRepoProphecy = $this->prophesize(FundRepository::class);
        $fundRepoProphecy->pullForCampaign($campaign)->shouldbeCalledOnce();

        $command = new UpdateCampaigns(
            $campaignRepoProphecy->reveal(),
            $this->getContainer()->get(EntityManagerInterface::class),
            $fundRepoProphecy->reveal(),
            new NullLogger(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:update-campaigns starting!',
            'Updated campaign someCampaignIdxxxx',
            'matchbot:update-campaigns complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testSingleUpdateNotFoundOnSalesforceOutsideProduction(): void
    {
        // This case should be skipped over without crashing, in non-production envs.

        $campaign = new Campaign(sfId: Salesforce18Id::ofCampaign('missingOnSfIDxxxxx'), charity: null);
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->findRecentLiveAndPendingGiftAidApproval()
            ->willReturn([$campaign])
            ->shouldBeCalledOnce();
        $campaignRepoProphecy->updateFromSf($campaign)->willThrow(NotFoundException::class)->shouldBeCalledOnce();

        $fundRepoProphecy = $this->prophesize(FundRepository::class);
        $fundRepoProphecy->pullForCampaign($campaign)->shouldNotBeCalled(); // Exception reached before this call

        $command = new UpdateCampaigns(
            $campaignRepoProphecy->reveal(),
            $this->getContainer()->get(EntityManagerInterface::class),
            $fundRepoProphecy->reveal(),
            new NullLogger(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:update-campaigns starting!',
            'Skipping unknown sandbox campaign missingOnSfIDxxxxx',
            'matchbot:update-campaigns complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testSingleUpdateHitsTransferExceptionTwice(): void
    {
        // Subclass of Guzzle TransferException
        $exception = new RequestException(
            'dummy exc message',
            new Request('GET', 'https://example.com'),
        );

        $campaign = new Campaign(sfId: Salesforce18Id::ofCampaign('someCampaignIdxxxx'), charity: null);
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->findRecentLiveAndPendingGiftAidApproval()
            ->willReturn([$campaign])
            ->shouldBeCalledOnce();
        $campaignRepoProphecy->updateFromSf($campaign)
            ->willThrow($exception)
            ->shouldBeCalledTimes(2);

        $fundRepoProphecy = $this->prophesize(FundRepository::class);
        $fundRepoProphecy->pullForCampaign($campaign)->shouldNotBeCalled(); // Exception reached before this call

        $command = new UpdateCampaigns(
            $campaignRepoProphecy->reveal(),
            $this->getContainer()->get(EntityManagerInterface::class),
            $fundRepoProphecy->reveal(),
            new NullLogger(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:update-campaigns starting!',
            'Skipping campaign someCampaignIdxxxx due to 2nd transfer error "dummy exc message"',
            'matchbot:update-campaigns complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    /**
     * This case should be quietly handled without extra OutputInterface output – it
     * will just add an info log for Monolog.
     */
    public function testSingleUpdateHitsTransferExceptionOnce(): void
    {
        // Subclass of Guzzle TransferException
        $exception = new RequestException(
            'dummy exc message',
            new Request('GET', 'https://example.com'),
        );

        $campaign = new Campaign(sfId: Salesforce18Id::ofCampaign('someCampaignIdxxxx'), charity: null);

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $mockBuilder = $this->getMockBuilder(CampaignRepository::class);
        $mockBuilder->setConstructorArgs([$entityManagerProphecy->reveal(), new ClassMetadata(Campaign::class)]);
        $mockBuilder->onlyMethods(['findRecentLiveAndPendingGiftAidApproval', 'updateFromSf']);

        $campaignRepo = $mockBuilder->getMock();
        $campaignRepo->expects($this->once())
            ->method('findRecentLiveAndPendingGiftAidApproval')
            ->willReturn([$campaign]);
        $campaignRepo->expects($this->exactly(2))
            ->method('updateFromSf')
            ->willReturnOnConsecutiveCalls(
                $this->throwException($exception),
                null,
            );

        $fundRepoProphecy = $this->prophesize(FundRepository::class);
        // On retry, this should succeed
        $fundRepoProphecy->pullForCampaign($campaign)->shouldBeCalledOnce();

        $command = new UpdateCampaigns(
            $campaignRepo,
            $this->getContainer()->get(EntityManagerInterface::class),
            $fundRepoProphecy->reveal(),
            new NullLogger(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:update-campaigns starting!',
            'Updated campaign someCampaignIdxxxx',
            'matchbot:update-campaigns complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testSingleUpdateSuccessWithAllOption(): void
    {
        $campaign = new Campaign(sfId: Salesforce18Id::ofCampaign('someCampaignIdxxxx'), charity: null);
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->findAll()
            ->willReturn([$campaign])
            ->shouldBeCalledOnce();
        $campaignRepoProphecy->updateFromSf($campaign)->shouldBeCalledOnce();

        $fundRepoProphecy = $this->prophesize(FundRepository::class);
        $fundRepoProphecy->pullForCampaign($campaign)->shouldbeCalledOnce();

        $command = new UpdateCampaigns(
            $campaignRepoProphecy->reveal(),
            $this->getContainer()->get(EntityManagerInterface::class),
            $fundRepoProphecy->reveal(),
            new NullLogger(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
        $commandTester->execute(['--all' => null]);

        $expectedOutputLines = [
            'matchbot:update-campaigns starting!',
            'Updated campaign someCampaignIdxxxx',
            'matchbot:update-campaigns complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }
}
