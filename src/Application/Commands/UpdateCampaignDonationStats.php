<?php

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignStatisticsRepository;
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
        private CampaignRepository $campaignRepository,
        private CampaignStatisticsRepository $campaignStatisticsRepository,
        private EntityManagerInterface $entityManager
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

        foreach ($campaigns as $campaign) {
            $this->handleCampaign($campaign, $output);
        }

        $output->writeln(sprintf('Updated statistics for %d campaigns with recent donations', count($campaigns)));
    }

    /**
     * Expects stats within 1 day for now. If this was necessary often after the initial stats population, we might
     * want to consider making it run only at quiet times. But assuming it isn't, it's OK to run as needed on any 'tick'.
     */
    private function updateOldMissedCampaigns(OutputInterface $output): void
    {
        $oldestExpectedWithoutStats = new \DateTimeImmutable('-1 day');
        $campaigns = $this->campaignRepository->findCampaignsWithNoRecentStats($oldestExpectedWithoutStats);

        foreach ($campaigns as $campaign) {
            $this->handleCampaign($campaign, $output);
        }

        $output->writeln(sprintf('Updated statistics for %d campaigns with no recent stats', count($campaigns)));
    }

    private function handleCampaign(Campaign $campaign, OutputInterface $output): void
    {
        $campaignId = $campaign->getId();
        \assert($campaignId !== null);
        $matchFundsUsed = $this->campaignRepository->totalMatchFundsUsed($campaignId);
        $donationSum = $this->campaignRepository->totalCoreDonations($campaign);

        $this->campaignStatisticsRepository->updateStatistics(
            campaign: $campaign,
            donationSum: $donationSum,
            amountRaised: $donationSum->plus($matchFundsUsed),
            matchFundsUsed: $matchFundsUsed,
        );
        $output->writeln("Prepared statistics for campaign ID {$campaignId}, SF ID {$campaign->getSalesforceId()}");
    }
}
