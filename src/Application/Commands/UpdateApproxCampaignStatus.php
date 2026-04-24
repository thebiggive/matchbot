<?php

namespace MatchBot\Application\Commands;

use MatchBot\Domain\CampaignStatisticsRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Updates approximate campaign statuses to support search results ordering.
 *
 * Will update any campaigns that are due to start within the next 24 hours to active, so doesn't need to be run
 * very frequently, suggested cadence is once every six hours.
 */
#[AsCommand(
    name: 'matchbot:update-approx-campaign-stats',
    description: "Updates approximate campaign statuses to support search results ordering.",
)]
class UpdateApproxCampaignStatus extends LockingCommand
{
    public function __construct(
        private CampaignStatisticsRepository $campaignStatisticsRepository,
    ) {
        parent::__construct();
    }
    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $this->campaignStatisticsRepository->updateApproxStatuses();

        return 0;
    }
}
