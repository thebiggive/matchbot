<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

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

        $statsCommand = $app->find('matchbot:send-statistics');
        $statsReturn = $statsCommand->run(
            new ArrayInput(['command' => 'matchbot:send-statistics']),
            $output
        );
        if ($statsReturn !== 0) {
            $output->writeln('Failed to send statistics');
            return $statsReturn;
        }

        $expireCommand = $app->find('matchbot:expire-match-funds');
        $expireReturn = $expireCommand->run(
            new ArrayInput(['command' => 'matchbot:expire-match-funds']),
            $output
        );
        if ($expireReturn !== 0) {
            $output->writeln('Failed to expire match funds');
            return $expireReturn;
        }

        $cancelStaleTipsCommand = $app->find('matchbot:cancel-stale-donation-fund-tips');
        $cancelStaleReturn = $cancelStaleTipsCommand->run(
            new ArrayInput(['command' => 'matchbot:cancel-stale-donation-fund-tips']),
            $output
        );
        if ($cancelStaleReturn !== 0) {
            $output->writeln('Failed cancel stale donation fund tips');
            return $expireReturn;
        }

        return 0;
    }
}
