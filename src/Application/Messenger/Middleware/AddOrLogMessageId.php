<?php

declare(strict_types=1);

namespace MatchBot\Application\Messenger\Middleware;

use MatchBot\Application\Messenger\DonationUpserted;
use Messages\Stamp\MessageId;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

readonly class AddOrLogMessageId implements MiddlewareInterface
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    /** @psalm-suppress PossiblyUnusedReturnValue Messenger uses value; our test doesn't */
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $existingMessageIdStamp = $envelope->last(MessageId::class);
        if ($existingMessageIdStamp === null) {
            $stamp = new MessageId();
            $envelope = $envelope->with($stamp);
            $this->logAddedMessageId($envelope, $stamp->getMessageId());
        } else {
            $this->logReceivedMessageId($envelope, $existingMessageIdStamp->getMessageId());
        }

        return $stack->next()->handle($envelope, $stack);
    }

    private function logAddedMessageId(Envelope $envelope, string $messageId): void
    {
        $message = $envelope->getMessage();
        $log = sprintf('AddOrLogMessageId stamped %s with message ID: %s', get_class($message), $messageId);
        $logWithUUID = $this->appendUUIDIfApplicable($message, $log);
        $this->logger->info($logWithUUID);
    }

    private function logReceivedMessageId(Envelope $envelope, string $messageId): void
    {
        $message = $envelope->getMessage();
        $log = sprintf('AddOrLogMessageId received %s with message ID: %s', get_class($message), $messageId);
        $logWithUUID = $this->appendUUIDIfApplicable($message, $log);
        $this->logger->info($logWithUUID);
    }

    private function appendUUIDIfApplicable(object $message, string $log): string
    {
        if ($message instanceof DonationUpserted) {
            $log .= ' (Donation UUID: ' . $message->uuid . ')';
        }

        return $log;
    }
}
