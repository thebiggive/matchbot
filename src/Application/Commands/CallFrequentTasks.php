<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Application\Assertion;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'matchbot:tick',
    description: 'Calls all per-minute commands; currently to expire old matching and send statistics'
)]
class CallFrequentTasks extends LockingCommand
{
    /** @var list<\MatchBot\Application\Commands\Command> */
    private array $commands;

    public function __construct(
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
