<?php

namespace MatchBot\Application\Commands;

use Assert\AssertionFailedException;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\MatchFundsService;
use Psr\Log\LoggerInterface;
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
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private MatchFundsService $matchFundsService,
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
            if ($this->handleCampaign($campaign, $output)) {
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
     * Expects stats within 1 day (@todo temporarily 1 hour) for now.
     * If this was necessary often after the initial stats population, we might
     * want to consider making it run only at quiet times. But assuming it isn't, it's OK to run as needed on any 'tick'.
     */
    private function updateOldMissedCampaigns(OutputInterface $output): void
    {
        $oldestExpectedWithoutStats = new \DateTimeImmutable('-1 hour');
        $campaigns = $this->campaignRepository->findCampaignsWithNoRecentStats($oldestExpectedWithoutStats);
        $numChanged = 0;

        foreach ($campaigns as $campaign) {
            if ($this->handleCampaign($campaign, $output)) {
                $numChanged++;
            }
        }

        $output->writeln(sprintf(
            'Updated statistics for %d of %d campaigns with no recent stats',
            $numChanged,
            count($campaigns),
        ));
    }

    /**
     * Creates or finds + updates a {@see CampaignStatistics} record, via eager loading from $campaign.
     * Doesn't flush, so callers need to when done building stats.
     *
     * @return bool whether or not the statistics have changed
     */
    private function handleCampaign(Campaign $campaign, OutputInterface $output): bool
    {
        if ($this->isIgnoredCampaign($campaign)) {
            return false;
        }

        $campaignId = $campaign->getId();
        \assert($campaignId !== null);
        $matchFundsUsed = $this->campaignRepository->totalMatchFundsUsed($campaignId);
        $donationSum = $this->campaignRepository->totalCoreDonations($campaign);

        $statistics = $campaign->getStatistics(); // New & zeroes if not done before.

        try {
            $changed = $statistics->setTotals(
                at: new \DateTimeImmutable('now'),
                donationSum: $donationSum,
                amountRaised: $donationSum->plus($matchFundsUsed),
                matchFundsUsed: $matchFundsUsed,
                matchFundsTotal: $this->matchFundsService->getTotalFunds($campaign),
                alwaysConsiderChanged: false,
            );
        } catch (AssertionFailedException $exception) {
            $errorMessage = "Error updating statistics for campaign ID {$campaignId} ({$campaign->getSalesforceId()}): {$exception->getMessage()}";
            $this->logger->error($errorMessage);
            $output->writeln("<error>$errorMessage</error>");
            return false; // Not re-throwing for now so that we can get a complete list of campaigns with issues in one go.
        }

        $this->entityManager->persist($statistics);

        if ($changed) {
            $output->writeln("Prepared statistics for campaign ID {$campaignId}, SF ID {$campaign->getSalesforceId()}");
        }

        return $changed;
    }

    private function isIgnoredCampaign(Campaign $campaign): bool
    {
        $excludeJson = getenv('KNOWN_OVERMATCHED_CAMPAIGN_IDS');
        $excludedCampaignIds = [];
        if (is_string($excludeJson) && $excludeJson !== '') {
            /** @var list<int> $excludedCampaignIds */
            $excludedCampaignIds = json_decode($excludeJson, true, 512, \JSON_THROW_ON_ERROR);
        }

        return in_array($campaign->getId(), $excludedCampaignIds, true);
    }
}
