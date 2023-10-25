<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Commands\ClaimGiftAid;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

class ClaimGiftAidTest extends TestCase
{
    use DonationTestDataTrait;

    public function testNothingToSend(): void
    {
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $donationRepoProphecy->findReadyToClaimGiftAid(false)
            ->willReturn([])
            ->shouldBeCalledOnce();

        $em = $this->prophesize(EntityManagerInterface::class);
        $em->persist(Argument::any())->shouldNotBeCalled();
        $em->flush()->shouldNotBeCalled();

        $bus = $this->prophesize(RoutableMessageBus::class);

        ['donation' => $testDonation, 'campaign' => $campaign, 'charity' => $charity] = $this->getTestDonation();
        $testDonation->setTbgShouldProcessGiftAid(true);
        $envelope = new Envelope(
            $testDonation->toClaimbotModel($campaign),
            $this->getExpectedStamps($testDonation->getUuid()),
        );
        $bus->dispatch($envelope)->shouldNotBeCalled();

        $commandTester = new CommandTester($this->getCommand($donationRepoProphecy,$campaignRepoProphecy, $em, $bus));
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:claim-gift-aid starting!',
            'Submitted 0 donations to the ClaimBot queue',
            'matchbot:claim-gift-aid complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testSend(): void
    {
        ['donation' => $testDonation, 'campaign' => $campaign, 'charity' => $charity] = $this->getTestDonation();
        $testDonation->setTbgShouldProcessGiftAid(true);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findReadyToClaimGiftAid(false)
            ->shouldBeCalledOnce()
            ->willReturn([$testDonation]);
        $capaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $capaignRepoProphecy->find(Argument::type('int'))->willReturn($campaign);

        $em = $this->prophesize(EntityManagerInterface::class);
        $em->persist($testDonation)->shouldBeCalledOnce();
        $em->flush()->shouldBeCalledOnce();

        $bus = $this->prophesize(RoutableMessageBus::class);

        $envelope = new Envelope(
            $testDonation->toClaimbotModel($campaign),
            $this->getExpectedStamps($testDonation->getUuid()),
        );
        $bus->dispatch($envelope)
            ->shouldBeCalledOnce()
            ->willReturn($envelope);

        $commandTester = new CommandTester($this->getCommand($donationRepoProphecy, $capaignRepoProphecy, $em, $bus));
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:claim-gift-aid starting!',
            'Submitted 1 donations to the ClaimBot queue',
            'matchbot:claim-gift-aid complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    public function testSendWithResends(): void
    {
        ['donation' => $testDonation, 'campaign' => $campaign, 'charity' => $charity] = $this->getTestDonation();
        $testDonation->setTbgShouldProcessGiftAid(true);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findReadyToClaimGiftAid(true)
            ->shouldBeCalledOnce()
            ->willReturn([$testDonation]);

        $campaignRepoProphecy = $this->prophesize(CampaignRepository::class);
        $campaignRepoProphecy->find(Argument::type('int'))->willReturn($campaign);
        $em = $this->prophesize(EntityManagerInterface::class);
        $em->persist($testDonation)->shouldBeCalledOnce();
        $em->flush()->shouldBeCalledOnce();

        $bus = $this->prophesize(RoutableMessageBus::class);

        $envelope = new Envelope(
            $testDonation->toClaimbotModel($campaign),
            $this->getExpectedStamps($testDonation->getUuid()),
        );
        $bus->dispatch($envelope)
            ->shouldBeCalledOnce()
            ->willReturn($envelope);

        $commandTester = new CommandTester($this->getCommand($donationRepoProphecy, $campaignRepoProphecy, $em, $bus));
        $commandTester->execute([
            '--with-resends' => null,
        ]);

        $expectedOutputLines = [
            'matchbot:claim-gift-aid starting!',
            'Submitted 1 donations to the ClaimBot queue',
            'matchbot:claim-gift-aid complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    private function getCommand(
        ObjectProphecy $donationRepoProphecy,
        ObjectProphecy $campaignRepoProphecy,
        ObjectProphecy $entityManagerProphecy,
        ObjectProphecy $routableBusProphecy,
    ): ClaimGiftAid {
        $command = new ClaimGiftAid(
            $donationRepoProphecy->reveal(),
            $campaignRepoProphecy->reveal(),
            $entityManagerProphecy->reveal(),
            $routableBusProphecy->reveal(),
        );
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));
        $command->setLogger(new NullLogger());

        return $command;
    }

    #[Pure] private function getExpectedStamps(string $uuid): array
    {
        return [
            new BusNameStamp('claimbot.donation.claim'),
            new TransportMessageIdStamp("claimbot.donation.claim.$uuid"),
        ];
    }
}
