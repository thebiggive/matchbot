<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignService;
use MatchBot\Domain\Salesforce18Id;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'matchbot:regenerate-stats-for-campaign',
    description: 'Regenerates campaign statistics for a campaign in case automatic processes failed to updated it in time'
)]
class RegenerateStatsForCampaign extends LockingCommand
{
    public function __construct(
        private EntityManagerInterface $em,
        private CampaignRepository $campaignRepository,
        private CampaignService $campaignService,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function configure(): void
    {
        $this->addArgument(
            'CampaignSFID',
            InputArgument::REQUIRED,
            '18 Character Salesforce ID of the campaign, as found in the donate page URI etc',
        );
    }
    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        // @phpstan-ignore cast.string
        $campaignId = Salesforce18Id::ofCampaign((string) $input->getArgument('CampaignSFID'));

        $campaign = $this->campaignRepository->findOneBySalesforceId($campaignId);

        if (!$campaign) {
            $this->logger->error("Campaign {$campaignId->value} not found, cannot regenerate stats");
            return 1;
        }

        $this->campaignService->regenerateStats($campaign);
        $this->em->flush();
        return 0;
    }
}
