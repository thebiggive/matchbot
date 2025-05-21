<?php

namespace MatchBot\Tests\Application\Messenger\Handler;

use MatchBot\Application\Messenger\Handler\DonationUpsertedHandler;
use MatchBot\Domain\DoctrineDonationRepository;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class DonationUpsertedHandlerTest extends TestCase
{
    use ProphecyTrait;

    /** @var ObjectProphecy<DonationRepository>  */
    private ObjectProphecy $donationRepositoryProphecy;

    #[\Override]
    public function setUp(): void
    {
        $this->donationRepositoryProphecy = $this->prophesize(DonationRepository::class);
    }

    public function testItPushesOneDonationToSf(): void
    {
        $message = self::someUpsertedMessage();

        $this->donationRepositoryProphecy->push($message)->shouldBeCalledOnce();

        $sut = new DonationUpsertedHandler(
            $this->donationRepositoryProphecy->reveal(),
            new NullLogger(),
        );

        $sut->__invoke($message);
    }

    /**
     * We catch any \Throwable so should get an alarm while Messenger still `ack()`s the message.
     */
    public function testItLogsErrorIfDonationCannotBePushed(): void
    {
        $message = self::someUpsertedMessage();

        $this->donationRepositoryProphecy->push($message)
            ->willThrow(new \Exception('Failed to push to SF'));

        $loggerWithOneError = $this->prophesize(LoggerInterface::class);
        $loggerWithOneError->info(Argument::type('string'));
        $loggerWithOneError->error(Argument::type('string'))->shouldBeCalledOnce();

        $sut = new DonationUpsertedHandler(
            $this->donationRepositoryProphecy->reveal(),
            $loggerWithOneError->reveal(),
        );

        $sut->__invoke($message);
    }
}
