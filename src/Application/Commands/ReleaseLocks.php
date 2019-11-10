<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Symfony\Component\Console\Output\OutputInterface;

class ReleaseLocks extends Command
{
    protected static $defaultName = 'matchbot:release-locks';

    /** @var LockingCommand[] */
    private $commands;

    /**
     * @param LockingCommand $command  LockingCommand we should be able to release locks for
     */
    public function addCommand(LockingCommand $command): void
    {
        $this->commands[] = $command;
    }

    protected function configure(): void
    {
        $this->setDescription("Releases all `LockingCommand`s' locks");
    }

    protected function doExecute(OutputInterface $output)
    {
        foreach ($this->commands as $command) {
            $output->writeln("Force-releasing lock for {$command->getName()}");
            $command->forceReleaseLock();
        }
    }
}
