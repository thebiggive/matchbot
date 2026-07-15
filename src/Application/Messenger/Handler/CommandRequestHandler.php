<?php

namespace MatchBot\Application\Messenger\Handler;

use MatchBot\Application\Environment;
use MatchBot\Application\Messenger\CommandRequest;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackHeaderBlock;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackSectionBlock;
use Symfony\Component\Notifier\Bridge\Slack\SlackOptions;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

/**
 * Takes the command string from a {@see CommandRequest} and gets Console to run it.
 */
#[AsMessageHandler]
readonly class CommandRequestHandler
{
    public function __construct(
        private \Symfony\Component\Console\Application $consoleApplication,
        private readonly ChatterInterface $chatter,
        private readonly Environment $environment,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CommandRequest $message): void
    {
        $this->consoleApplication->setAutoExit(false);
        $this->consoleApplication->setCatchExceptions(true);

        // No stamps because CircleCI handles the publish, so invent a UUID to uniquely identify
        // the task when it ends.
        $commandRunUuid = Uuid::uuid4()->toString();
        $startedLog = sprintf(
            '%s starting with ID %s for command %s',
            __CLASS__,
            $commandRunUuid,
            $message->command,
        );
        $this->logger->info($startedLog);
        $this->sendToSlack($startedLog);

        try {
            $exitCode = $this->consoleApplication->run(
                new StringInput($message->command),
                new ConsoleOutput()
            );
        } catch (\Throwable $throwable) {
            $this->logger->error(sprintf(
                'Command run %s failed with throwable code %s.',
                $commandRunUuid,
                $throwable->__toString()
            ));

            throw $throwable;
        }

        if ($exitCode !== 0) {
            // Exception handler will also relay this one to Slack, to the env's alarms channel.
            $this->logger->error(sprintf(
                'Command run %s failed with exit code %d.',
                $commandRunUuid,
                $exitCode
            ));
        }

        $statusAdjective = $exitCode === 0 ? 'successfully' : 'with errors (see alarm)';
        $finishedLog = sprintf(
            '%s finished %s with ID %s. See matchbot logs for details.',
            __CLASS__,
            $statusAdjective,
            $commandRunUuid,
        );
        $this->logger->info($finishedLog);
        $this->sendToSlack($finishedLog);
    }

    private function sendToSlack(string $details): void
    {
        if ($this->environment === Environment::Test) {
            return;
        }

        $chatMessage = new ChatMessage('Manual command run');
        $options = (new SlackOptions())
            ->block(new SlackHeaderBlock(sprintf('[%s] %s', $this->environment->name, 'Manual command run')))
            ->block(new SlackSectionBlock()->text($details));
        $chatMessage->options($options);
        $this->chatter->send($chatMessage);
    }
}
