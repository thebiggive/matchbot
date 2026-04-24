<?php

namespace MatchBot\Application\Commands;

use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignStatistics;
use MatchBot\Domain\CampaignStatisticsRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Finds campaigns that with approx status of 'Preview' that are open or will be open soon and updates
 * to Active, and finds campaigns that with approx status of 'Active' that are closed and updates to 'Expired'
 */
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
