<?php

namespace MatchBot\Monolog\Handler;

use Monolog\Handler\HandlerInterface;
use Monolog\Logger;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackHeaderBlock;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackSectionBlock;
use Symfony\Component\Notifier\Bridge\Slack\SlackOptions;
use Symfony\Component\Notifier\ChatterInterface;
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
    public function __construct(private ChatterInterface $slackConnction)
    {

    }

    public function isHandling(array $record): bool
    {
        return $record['level'] >= Logger::ERROR;
    }

    /**
     * @psalm-suppress PossiblyUnusedReturnValue - used by Monolog.
     */
    public function handle(array|\ArrayAccess $record): bool
    {
        if ($record['level'] < Logger::ERROR) {
            return false; // allows another handle to handle this.
         }

        $message = $record['message'];
        $levelName = $record['level_name'];

        $lines = explode(PHP_EOL, $message);

        $messageFirstLine = $lines[0] ?? '';
        $messageFirstSeveralLines = implode(\PHP_EOL, array_slice($lines,0,9)) . \PHP_EOL;

        $heading = "Matchbot $levelName: $messageFirstLine";

        $chatMessage = new ChatMessage($heading);
        $options = (new SlackOptions())
            // For now, do a simple truncate at the max, 150 chars, since most messages are shorter and the next line
            // has the full text anyway.
            ->block((new SlackHeaderBlock(substr($heading, 0, 150))))
            ->block((new SlackSectionBlock())->text($messageFirstSeveralLines));
        $chatMessage->options($options);

        $this->slackConnction->send($chatMessage);

        return false; // record will go to other handlers in addition to being sent to slack.
    }

    public function handleBatch(array $records): void
    {
        foreach ($records as $record) {
            $this->handle($record);
        }
    }

    public function close(): void
    {
        // no-op;
    }
}
