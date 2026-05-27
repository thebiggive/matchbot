<?php

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'matchbot:update-campaign-donation-stats',
    description: 'Updates CampaignStatistics for every campaign with recent donations'
)]
class UpdateCampaignDonationStats extends LockingCommand
{
    public function __construct(
        private readonly CampaignRepository $campaignRepository,
        private readonly CampaignService $campaignService,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $this->updateRecentlyUpdatedDonationCampaigns($output);
        $this->entityManager->flush(); // Need to ensure we don't try to insert the same stat row twice.

        $this->updateOldMissedCampaigns($output);
        $this->entityManager->flush();

        return 0;
    }

    private function updateRecentlyUpdatedDonationCampaigns(OutputInterface $output): void
    {
        // If the 'tick' is completing quickly it runs every minute; if another one has a lock because
        // it's slower it may be a bit longer. Check 5 minutes back as standard, and there is also a mop-up task
        // to fill in all campaigns with outdated or no stats.
        $campaigns = $this->campaignRepository->findWithDonationChangesSince(new \DateTimeImmutable('-5 minutes'));
        $numChanged = 0;

        foreach ($campaigns as $campaign) {
            if ($this->campaignService->regenerateStats($campaign)) {
                $numChanged++;
            }
        }

        $output->writeln(sprintf(
            'Updated statistics for %d of %d campaigns with recent donations',
            $numChanged,
            count($campaigns),
        ));
    }

    /**
     * Expects stats within 1 day for now. If this was necessary often after the initial stats population, we might
     * want to consider making it run only at quiet times. But assuming it isn't, it's OK to run as needed on any 'tick'.
     */
    private function updateOldMissedCampaigns(OutputInterface $output): void
    {
        $oldestExpectedWithoutStats = new \DateTimeImmutable('-1 day');
        $campaigns = $this->campaignRepository->findCampaignsWithNoRecentStats($oldestExpectedWithoutStats);
        $numChanged = 0;

        foreach ($campaigns as $campaign) {
            if ($this->campaignService->regenerateStats($campaign)) {
                $numChanged++;
            }
        }

        $output->writeln(sprintf(
            'Updated statistics for %d of %d campaigns with no recent stats',
            $numChanged,
            count($campaigns),
        ));
    }
}
