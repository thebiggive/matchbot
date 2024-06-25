<?php

namespace MatchBot\Tests\Application\Messenger\Handler;

use MatchBot\Application\Messenger\DonationCreated;
use MatchBot\Application\Messenger\Handler\DonationCreatedHandler;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class DonationCreatedHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<DonationRepository>  */
    private ObjectProphecy $donationRepositoryProphecy;

    public function setUp(): void
    {
        $this->donationRepositoryProphecy = $this->prophesize(DonationRepository::class);
    }

    public function testItPushesOneDonationToSf(): void
    {
        $donation = self::someDonation();
        $this->donationRepositoryProphecy->findOneBy(['uuid' => $donation->getUuid()])->willReturn($donation);
        $this->donationRepositoryProphecy->push(Argument::type(DonationCreated::class), true)->shouldBeCalledOnce();

        $sut = new DonationCreatedHandler(
            $this->donationRepositoryProphecy->reveal(),
            new NullLogger(),
        );

        $sut->__invoke(self::someCreatedMessage());
    }

    /**
     * We catch any \Throwable so should get an alarm while Messenger still `ack()`s the message.
     */
    public function testItLogsErrorIfDonationCannotBePushed(): void
    {
        $donation = self::someDonation();
        $this->donationRepositoryProphecy->findOneBy(['uuid' => $donation->getUuid()])->willReturn($donation);
        $this->donationRepositoryProphecy->push(Argument::type(DonationCreated::class), true)
            ->willThrow(new \Exception('Failed to push to SF'));

        $loggerWithOneError = $this->prophesize(LoggerInterface::class);
        $loggerWithOneError->info(Argument::type('string'));
        $loggerWithOneError->error(Argument::type('string'))->shouldBeCalledOnce();

        $sut = new DonationCreatedHandler(
            $this->donationRepositoryProphecy->reveal(),
            $loggerWithOneError->reveal(),
        );

        $sut->__invoke(self::someCreatedMessage());
    }
}
