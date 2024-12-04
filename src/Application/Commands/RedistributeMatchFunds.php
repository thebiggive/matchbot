<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Application\Matching\MatchFundsRedistributor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'matchbot:redistribute-match-funds',
    description: 'Moves match funding allocations from lower to higher priority funds where possible'
)]
class RedistributeMatchFunds extends LockingCommand
{
    public function __construct(
        private LockFactory $lockFactory,
        private MatchFundsRedistributor $matchFundsRedistributor,
    ) {
        parent::__construct();
    }

    public function configure()
    {
        $this->addArgument(
            'mode',
            InputArgument::OPTIONAL,
            '"patch-2024-12-04" for temporary patch, or omit.'
        );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        /** @var ?string $mode */
        $mode = $input->getArgument('mode');
        if ($mode === 'patch-2024-12-04') {
            $this->ensureOneTimePatchNotDone();
            $this->matchFundsRedistributor->patch4DecemberDonation();
            $output->writeln("Ran one-off patch-2024-12-04.");

            return 0;
        }

        [$numberChecked, $donationsAmended] = $this->matchFundsRedistributor->redistributeMatchFunds();
        $output->writeln("Checked $numberChecked donations and redistributed matching for $donationsAmended");

        return 0;
    }

    /**
     * @throws \Symfony\Component\Lock\Exception\LockAcquiringException
     */
    private function ensureOneTimePatchNotDone(): void
    {
        $oneTimeFixLock = $this->lockFactory->createLock(
            resource: 'redistribute-2024-12-04-patch',
            ttl: 2 * 24 * 60 * 60, // 2 days
        );
        $oneTimeFixLock->acquire();
    }
}
