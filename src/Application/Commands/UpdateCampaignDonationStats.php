<?php

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
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
        // If the 'tick' is completing quickly it runs every minute; if another one has a lock because
        // it's slower it may be a bit longer. Check 5 minutes back as standard, and there will also soon
        // be a mop-up task to fill in all campaigns with outdated or no stats. @todo-MAT-413 update this comment.
        $campaigns = $this->campaignRepository->findWithDonationChangesSince(new \DateTimeImmutable('-5 minutes'));

        foreach ($campaigns as $campaign) {
            $campaignId = $campaign->getId();
            \assert($campaignId !== null);
            $matchFundsUsed = $this->campaignRepository->totalMatchFundsUsed($campaignId);
            $amountRaised = $this->campaignRepository->totalAmountRaised($campaignId, $matchFundsUsed);
            $this->campaignStatisticsRepository->updateStatistics($campaign, $amountRaised, $matchFundsUsed);
            $output->writeln("Prepared statistics for campaign ID {$campaignId}, SF ID {$campaign->getSalesforceId()}");
        }

        $this->entityManager->flush();

        $output->writeln(sprintf('Updated statistics for %d campaigns with recent donations', count($campaigns)));

        return 0;
    }
}
