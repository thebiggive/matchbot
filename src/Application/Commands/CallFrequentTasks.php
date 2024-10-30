<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Application\Assertion;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'matchbot:tick',
    description: 'Calls all per-minute commands; currently to expire old matching and send statistics'
)]
class CallFrequentTasks extends LockingCommand
{
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $app = $this->getApplication();
        \assert($app instanceof Application);

        $commandNames = [
            'matchbot:send-statistics',
            'matchbot:expire-match-funds',
//            'matchbot:cancel-stale-donation-fund-tips',
        ];

        $commands = array_map($app->find(...), $commandNames);
        Assertion::allIsInstanceOf($commands, Command::class);

        foreach ($commands as $command) {
            $return = $command->run(
                new ArrayInput(['command' => $command->getName()]),
                $output
            );

            if ($return !== 0) {
                $output->writeln("Failed run {$command->getName()}");
                return $return;
            }
        }

        return 0;
    }
}
