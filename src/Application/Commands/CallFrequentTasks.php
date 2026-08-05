<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\LockFactory;

#[AsCommand(
    name: 'matchbot:tick',
    description: 'Calls all per-minute commands; currently to expire old matching and send statistics'
)]
class CallFrequentTasks extends LockingCommand
{
    /** @var list<\MatchBot\Application\Commands\Command> */
    private array $commands;

    public function __construct(
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
        SendStatistics $sendStatistics,
        ExpireMatchFunds $expireMatchFunds,
        ExpirePendingMandates $expirePendingMandates,
        CancelStaleDonationFundTips $cancelStaleDonationFundTips,
        UpdateCampaignDonationStats $updateCampaignDonationStats,
        DeleteOldTestFunds $deleteOldTestFunds,
        UpdateApproxCampaignStatus $updateApproxCampaignStatus,
    ) {
        parent::__construct();
        $this->commands = [
            $sendStatistics,
            $expireMatchFunds,
            $expirePendingMandates,
            $cancelStaleDonationFundTips,
            $updateCampaignDonationStats,
            $deleteOldTestFunds,
            $updateApproxCampaignStatus,
        ];
    }

    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        foreach ($this->commands as $command) {
            if ($command instanceof LockingCommand) {
                $command->setLockFactory($this->lockFactory);
                $command->setLogger($this->logger);
            }

            $return = $command->run(
                new ArrayInput([]),
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
