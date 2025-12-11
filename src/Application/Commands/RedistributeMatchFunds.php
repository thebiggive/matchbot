<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Application\Matching\MatchFundsRedistributor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'matchbot:redistribute-match-funds',
    description: 'Moves match funding allocations from lower to higher priority funds where possible'
)]
class RedistributeMatchFunds extends LockingCommand
{
    /** @var \Closure():void  */
    public \Closure $simulatedParallelProcess;

    public function __construct(
        private MatchFundsRedistributor $matchFundsRedistributor,
    ) {
        $this->simulatedParallelProcess = function (): void {
            return;
        };

        parent::__construct();
    }

    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        [$numberChecked, $donationsAmended] = $this->matchFundsRedistributor->redistributeMatchFunds($this->simulatedParallelProcess);
        $output->writeln("Checked $numberChecked donations and redistributed matching for $donationsAmended");

        return 0;
    }
}
