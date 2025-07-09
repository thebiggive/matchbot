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
use Prophecy\Argument;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;

class UpdateCampaignsTest extends TestCase
{
    private \DateTimeImmutable $now;

    #[\Override]
    public function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2020-01-01T00:00:00z');
    }

    public function testSingleUpdateSuccess(): void
    {
        $campaign = TestCase::someCampaign(sfId: Salesforce18Id::ofCampaign('SOMeCAMPaIGNIdXXXX'));
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->findCampaignsThatNeedToBeUpToDate()
            ->willReturn([$campaign])
            ->shouldBeCalledOnce();
        $campaignRepoProphecy->updateFromSf($campaign)->shouldBeCalledOnce();

        $fundRepoProphecy = $this->prophesize(FundRepository::class);
        $fundRepoProphecy->pullForCampaign($campaign, $this->now)->shouldBeCalledOnce();

        $command = new UpdateCampaigns(
            $campaignRepoProphecy->reveal(),
            $this->getContainer()->get(EntityManagerInterface::class),
            $fundRepoProphecy->reveal(),
            new NullLogger(),
            $this->now,
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
        $campaignRepoProphecy->findCampaignsThatNeedToBeUpToDate()
            ->willReturn([$campaign])
            ->shouldBeCalledOnce();
        $campaignRepoProphecy->updateFromSf($campaign)->willThrow(NotFoundException::class)->shouldBeCalledOnce();

        $fundRepoProphecy = $this->prophesize(FundRepository::class);
        $fundRepoProphecy->pullForCampaign($campaign, $this->now)->shouldNotBeCalled(); // Exception reached before this call

        $command = new UpdateCampaigns(
            $campaignRepoProphecy->reveal(),
            $this->getContainer()->get(EntityManagerInterface::class),
            $fundRepoProphecy->reveal(),
            new NullLogger(),
            $this->now
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
        // Subclass of Guzzle TransferException
        $exception = new RequestException(
            'dummy exc message',
            new Request('GET', 'https://example.com'),
        );

        $campaign = TestCase::someCampaign(
            sfId: Salesforce18Id::ofCampaign('SOMeCAMPaIGNIdXXXX'),
        )
        ;
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->findCampaignsThatNeedToBeUpToDate()
            ->willReturn([$campaign])
            ->shouldBeCalledOnce();
        $campaignRepoProphecy->updateFromSf($campaign)
            ->willThrow($exception)
            ->shouldBeCalledTimes(2);

        $fundRepoProphecy = $this->prophesize(FundRepository::class);
        $fundRepoProphecy->pullForCampaign($campaign, $this->now)->shouldNotBeCalled(); // Exception reached before this call

        $command = new UpdateCampaigns(
            $campaignRepoProphecy->reveal(),
            $this->getContainer()->get(EntityManagerInterface::class),
            $fundRepoProphecy->reveal(),
            new NullLogger(),
            $this->now
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
        $mockBuilder->onlyMethods(['findCampaignsThatNeedToBeUpToDate', 'updateFromSf']);

        $campaignRepo = $mockBuilder->getMock();
        $campaignRepo->expects($this->once())
            ->method('findCampaignsThatNeedToBeUpToDate')
            ->willReturn([$campaign]);
        $campaignRepo->expects($this->exactly(2))
            ->method('updateFromSf')
            ->willReturnOnConsecutiveCalls(
                $this->throwException($exception),
                null,
            );

        $fundRepoProphecy = $this->prophesize(FundRepository::class);
        // On retry, this should succeed
        $fundRepoProphecy->pullForCampaign($campaign, $this->now)->shouldBeCalledOnce();

        $command = new UpdateCampaigns(
            $campaignRepo,
            $this->getContainer()->get(EntityManagerInterface::class),
            $fundRepoProphecy->reveal(),
            new NullLogger(),
            $this->now
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
        $campaignRepoProphecy->updateFromSf($campaign)->shouldBeCalledOnce();

        $fundRepoProphecy = $this->prophesize(FundRepository::class);
        $fundRepoProphecy->pullForCampaign($campaign, $this->now)->shouldBeCalledOnce();

        $command = new UpdateCampaigns(
            $campaignRepoProphecy->reveal(),
            $this->getContainer()->get(EntityManagerInterface::class),
            $fundRepoProphecy->reveal(),
            new NullLogger(),
            $this->now
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
