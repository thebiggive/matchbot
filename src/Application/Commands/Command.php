<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class Command extends SymfonyCommand
{
    abstract protected function doExecute(OutputInterface $output);

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->start($output);
        $this->doExecute($output);
        $this->finish($output);
    }

    protected function start(OutputInterface $output): void
    {
        $output->writeln($this->getName() . ' starting!');
    }

    protected function finish(OutputInterface $output): void
    {
        $output->writeln($this->getName() . ' complete!');
    }
}
