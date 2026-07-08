<?php

namespace MatchBot\Application\Messenger\Handler;

use MatchBot\Application\Messenger\CommandRequest;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Takes the command string from a {@see CommandRequest} and gets Console to run it.
 */
#[AsMessageHandler]
readonly class CommandRequestHandler
{
    public function __construct(
        private \Symfony\Component\Console\Application $consoleApplication,
    ) {
    }

    public function __invoke(CommandRequest $message): void
    {
        $this->consoleApplication->setAutoExit(false);
        $this->consoleApplication->setCatchExceptions(false);

        $exitCode = $this->consoleApplication->run(
            new StringInput($message->command),
            new ConsoleOutput()
        );

        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf(
                'Command "%s" failed with exit code %d.',
                $message->command,
                $exitCode
            ));
        }
    }
}
