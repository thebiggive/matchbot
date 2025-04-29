<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class Command extends SymfonyCommand
{
    public const string CLI_OPTION_NOLOG = 'nolog';

    abstract protected function doExecute(InputInterface $input, OutputInterface $output): int;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->start($input, $output);
        $return = $this->doExecute($input, $output);
        $this->finish($input, $output);

        return $return;
    }

    protected function start(InputInterface $input, OutputInterface $output): void
    {
        if ($this->noLog($input)) {
            return;
        }

        $output->writeln(($this->getName() ?? self::class) . ' starting!');
    }

    protected function finish(InputInterface $input, OutputInterface $output): void
    {
        if ($this->noLog($input)) {
            return;
        }

        $output->writeln(($this->getName() ?? self::class) . ' complete!');
    }

    private function noLog(InputInterface $input): bool
    {
        return $input->hasOption(self::CLI_OPTION_NOLOG) && $input->getOption(self::CLI_OPTION_NOLOG);
    }
}
