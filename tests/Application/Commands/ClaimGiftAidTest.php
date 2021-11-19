<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Commands\ClaimGiftAid;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Prophecy\ObjectProphecy;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

class ClaimGiftAidTest extends TestCase
{
    use DonationTestDataTrait;
    use ProphecyTrait;

    public function testNothingToSend(): void
    {
        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findReadyToClaimGiftAid()
            ->willReturn([])
            ->shouldBeCalledOnce();

        $bus = $this->prophesize(RoutableMessageBus::class);

        $testDonation = $this->getTestDonation();
        $testDonation->setTbgShouldProcessGiftAid(true);
        $envelope = new Envelope(
            $testDonation->toClaimBotModel(),
            $this->getExpectedStamps($testDonation->getUuid()),
        );
        $bus->dispatch($envelope)->shouldNotBeCalled();

        $commandTester = new CommandTester($this->getCommand($donationRepoProphecy, $bus));
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
        $testDonation = $this->getTestDonation();
        $testDonation->setTbgShouldProcessGiftAid(true);

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findReadyToClaimGiftAid()
            ->shouldBeCalledOnce()
            ->willReturn([$testDonation]);

        $bus = $this->prophesize(RoutableMessageBus::class);

        $envelope = new Envelope(
            $testDonation->toClaimBotModel(),
            $this->getExpectedStamps($testDonation->getUuid()),
        );
        $bus->dispatch($envelope)
            ->shouldBeCalledOnce()
            ->willReturn($envelope);

        $commandTester = new CommandTester($this->getCommand($donationRepoProphecy, $bus));
        $commandTester->execute([]);

        $expectedOutputLines = [
            'matchbot:claim-gift-aid starting!',
            'Submitted 1 donations to the ClaimBot queue',
            'matchbot:claim-gift-aid complete!',
        ];
        $this->assertEquals(implode("\n", $expectedOutputLines) . "\n", $commandTester->getDisplay());
        $this->assertEquals(0, $commandTester->getStatusCode());
    }

    private function getCommand(ObjectProphecy $donationRepoProphecy, ObjectProphecy $routableBusProphecy): ClaimGiftAid
    {
        $command = new ClaimGiftAid($donationRepoProphecy->reveal(), $routableBusProphecy->reveal());
        $command->setLockFactory(new LockFactory(new AlwaysAvailableLockStore()));

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
