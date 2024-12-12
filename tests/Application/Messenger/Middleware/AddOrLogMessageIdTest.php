<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Messenger\Middleware;

use MatchBot\Application\Messenger\Middleware\AddOrLogMessageId;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Messages\Stamp\MessageId;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\StackMiddleware;

class AddOrLogMessageIdTest extends TestCase
{
    use DonationTestDataTrait;

    public function testAddsMessageIdStamp(): void
    {
        $loggerProphecy = $this->prophesize(LoggerInterface::class);
        $this->expectLog(
            $loggerProphecy,
            'AddOrLogMessageId stamped MatchBot\Application\Messenger\DonationUpserted ' .
                'with message ID: ',
        );

        $middleware = new AddOrLogMessageId($loggerProphecy->reveal());

        $envelope = new Envelope(DonationUpserted::fromDonation($this->getTestDonation()));
        $middleware->handle($envelope, $this->createStack($middleware));
    }

    public function testLogsReceivedMessageId(): void
    {
        $loggerProphecy = $this->prophesize(LoggerInterface::class);
        $this->expectLog(
            $loggerProphecy,
            'AddOrLogMessageId received MatchBot\Application\Messenger\DonationUpserted ' .
                'with message ID: ',
        );
        $middleware = new AddOrLogMessageId($loggerProphecy->reveal());

        $envelope = new Envelope(DonationUpserted::fromDonation($this->getTestDonation()));
        $envelope = $envelope->with(new MessageId());
        $middleware->handle($envelope, $this->createStack($middleware));
    }

    /**
     * @param ObjectProphecy<LoggerInterface> $logger
     */
    private function expectLog(ObjectProphecy $logger, string $startOfMessage): void
    {
        $logger->info(Argument::containingString($startOfMessage))->shouldBeCalledOnce();
    }

    private function createStack(AddOrLogMessageId $middleware): StackMiddleware
    {
        return new StackMiddleware([$middleware]);
    }
}
