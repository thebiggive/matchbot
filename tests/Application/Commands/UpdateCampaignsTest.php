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
use MatchBot\Domain\CampaignService;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

/**
 * Note that this command only actually pulls funds *for* campaigns now.
 */
class UpdateCampaignsTest extends TestCase
{
    public function testSingleUpdateSuccess(): void
    {
        $campaign = TestCase::someCampaign(sfId: Salesforce18Id::ofCampaign('SOMeCAMPaIGNIdXXXX'));
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->findCampaignsWhereFundsNeedToBeUpToDate()
            ->willReturn([$campaign])
            ->shouldBeCalledOnce();

        $campaignServiceProphecy = $this->prophesize(CampaignService::class);
        $campaignServiceProphecy->pullFundsAndUpdateStats($campaign)->shouldBeCalledOnce();

        $command = new UpdateCampaigns(
            $campaignRepoProphecy->reveal(),
            $this->getContainer()->get(EntityManagerInterface::class),
            $campaignServiceProphecy->reveal(),
            new NullLogger(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:update-campaigns starting!',
            'Updated campaign SOMeCAMPaIGNIdXXXX',
            'matchbot:update-campaigns complete!',
        ];
        $this->assertSame(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testSingleUpdateNotFoundOnSalesforceOutsideProduction(): void
    {
        // This case should be skipped over without crashing, in non-production envs.

        $campaign = TestCase::someCampaign(
            sfId: Salesforce18Id::ofCampaign('MISsINGOnSFIDxXXXX'),
        );
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->findCampaignsWhereFundsNeedToBeUpToDate()
            ->willReturn([$campaign])
            ->shouldBeCalledOnce();

        $campaignServiceProphecy = $this->prophesize(CampaignService::class);
        $campaignServiceProphecy->pullFundsAndUpdateStats($campaign)
            ->willThrow(NotFoundException::class)
            ->shouldBeCalledOnce();

        $command = new UpdateCampaigns(
            $campaignRepoProphecy->reveal(),
            $this->getContainer()->get(EntityManagerInterface::class),
            $campaignServiceProphecy->reveal(),
            new NullLogger(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:update-campaigns starting!',
            'Skipping unknown sandbox campaign MISsINGOnSFIDxXXXX',
            'matchbot:update-campaigns complete!',
        ];
        $this->assertSame(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testSingleUpdateHitsTransferExceptionTwice(): void
    {
        $campaign = TestCase::someCampaign(
            sfId: Salesforce18Id::ofCampaign('SOMeCAMPaIGNIdXXXX'),
        );

        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->findCampaignsWhereFundsNeedToBeUpToDate()
            ->willReturn([$campaign])
            ->shouldBeCalledOnce();

        $campaignServiceProphecy = $this->prophesize(CampaignService::class);
        $campaignServiceProphecy->pullFundsAndUpdateStats($campaign)
            ->willThrow(NotFoundException::class)
            ->shouldBeCalledTimes(2);

        $command = new UpdateCampaigns(
            $campaignRepoProphecy->reveal(),
            $this->getContainer()->get(EntityManagerInterface::class),
            $campaignServiceProphecy->reveal(),
            new NullLogger(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:update-campaigns starting!',
            'Skipping campaign SOMeCAMPaIGNIdXXXX due to 2nd transfer error "dummy exc message"',
            'matchbot:update-campaigns complete!',
        ];
        $this->assertSame(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
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

        $campaign = TestCase::someCampaign(
            sfId: Salesforce18Id::ofCampaign('SOMeCAMPaIGNIdXXXX'),
        );

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $mockBuilder = $this->getMockBuilder(CampaignRepository::class);
        $mockBuilder->setConstructorArgs([$entityManagerProphecy->reveal(), new ClassMetadata(Campaign::class)]);
        $mockBuilder->onlyMethods(['findCampaignsWhereFundsNeedToBeUpToDate']);

        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->findCampaignsWhereFundsNeedToBeUpToDate()
            ->willReturn([$campaign])
            ->shouldBeCalledOnce();

        $campaignServiceMockBuilder = $this->getMockBuilder(CampaignService::class);
        $campaignServiceMockBuilder->setConstructorArgs([$entityManagerProphecy->reveal(), new ClassMetadata(Campaign::class)]);

        $campaignService = $campaignServiceMockBuilder->getMock();
        $campaignService->expects($this->exactly(2))
            ->method('pullFundsAndUpdateStats')
            ->willReturnOnConsecutiveCalls(
                $this->throwException($exception),
                null,
            );

        $command = new UpdateCampaigns(
            $campaignRepoProphecy->reveal(),
            $this->getContainer()->get(EntityManagerInterface::class),
            $campaignService,
            new NullLogger(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:update-campaigns starting!',
            'Updated campaign SOMeCAMPaIGNIdXXXX',
            'matchbot:update-campaigns complete!',
        ];
        $this->assertSame(
            implode("\n", $expectedOutputLines) . "\n",
            $commandTester->getDisplay()
        );
        $this->assertSame(0, $commandTester->getStatusCode());
    }

    public function testSingleUpdateSuccessWithAllOption(): void
    {
        $campaign = TestCase::someCampaign(
            sfId: Salesforce18Id::ofCampaign('SOMeCAMPaIGNIdXXXX'),
        );
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->findAll()
            ->willReturn([$campaign])
            ->shouldBeCalledOnce();

        $campaignServiceProphecy = $this->prophesize(CampaignService::class);
        $campaignServiceProphecy->pullFundsAndUpdateStats($campaign)->shouldBeCalledOnce();

        $command = new UpdateCampaigns(
            $campaignRepoProphecy->reveal(),
            $this->getContainer()->get(EntityManagerInterface::class),
            $campaignServiceProphecy->reveal(),
            new NullLogger(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        $commandTester = new CommandTester($command);
        $commandTester->execute(['--all' => null]);

        $expectedOutputLines = [
            'matchbot:update-campaigns starting!',
            'Updated campaign SOMeCAMPaIGNIdXXXX',
            'matchbot:update-campaigns complete!',
        ];
        $this->assertSame(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertSame(0, $commandTester->getStatusCode());
    }
}
