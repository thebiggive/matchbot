<?php

namespace MatchBot\Monolog\Handler;

use Monolog\Handler\HandlerInterface;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackHeaderBlock;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackSectionBlock;
use Symfony\Component\Notifier\Bridge\Slack\SlackOptions;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Exception\TransportException;
use Symfony\Component\Notifier\Message\ChatMessage;

/**
 * Sends error messages to our slack alarm channel so we deal with the errors without unnecessary delay.
 *
 * @phpstan-import-type Record from \Monolog\Logger
 * @phpstan-import-type Level from \Monolog\Logger
 * @psalm-import-type Record from \Monolog\Logger
 * @psalm-import-type Level from \Monolog\Logger
 *
 */
class SlackHandler implements HandlerInterface
{
    public function __construct(private ChatterInterface $slackConnction, private LoggerInterface $logger)
    {
    }

    #[\Override]
    public function isHandling(array $record): bool
    {
        return $record['level'] >= Logger::ERROR;
    }

    /**
     * @param array<string, mixed>|\ArrayAccess<string,mixed> $record
     * @psalm-suppress PossiblyUnusedReturnValue - used by Monolog.
     */
    #[\Override]
    public function handle(array|\ArrayAccess $record): bool
    {
        if ($record['level'] < Logger::ERROR) {
            return false; // allows another handle to handle this.
        }

        /** @var string $message */
        $message = $record['message'];

        /** @var string $levelName */
        $levelName = $record['level_name'];

        $lines = explode(PHP_EOL, $message);

        $messageFirstLine = $lines[0];
        $messageFirstSeveralLines = implode(\PHP_EOL, array_slice($lines, 0, 9)) . \PHP_EOL;

        $heading = "Matchbot $levelName: $messageFirstLine";

        $chatMessage = new ChatMessage($heading);
        $options = (new SlackOptions())
            // For now, do a simple truncate at the max, 150 chars, since most messages are shorter and the next line
            // usually has the full text anyway.
            ->block((new SlackHeaderBlock(substr($heading, 0, 150))))
            // Text block is also limited to 3000 characters, so must truncate to not crash.
            ->block((new SlackSectionBlock())->text(substr($messageFirstSeveralLines, 0, 3000)));
        $chatMessage->options($options);

        try {
            $this->slackConnction->send($chatMessage);
        } catch (TransportException $exception) {
            // logging as warning not error to avoid endless loop. This handler is only used for errors.
            $this->logger->warning("Failed to send error to slack:" . $exception->getMessage() . " for message: " . $message);
        }

        return false; // record will go to other handlers in addition to being sent to slack.
    }

    #[\Override]
    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            $this->handle($record);
        }
    }

    #[\Override]
    public function close(): void
    {
        // no-op;
    }
}
