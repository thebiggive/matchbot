<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\FundRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateCampaigns extends LockingCommand
{
    protected static $defaultName = 'matchbot:update-campaigns';

    private CampaignRepository $campaignRepository;
    private FundRepository $fundRepository;

    public function __construct(CampaignRepository $campaignRepository, FundRepository $fundRepository)
    {
        $this->campaignRepository = $campaignRepository;
        $this->fundRepository = $fundRepository;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Pulls down and saves the latest details of already-known Campaigns from Salesforce');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        /** @var Campaign[] $campaigns */
        $campaigns = $this->campaignRepository->findAll();
        foreach ($campaigns as $campaign) {
            $this->campaignRepository->pull($campaign);
            $this->fundRepository->pullForCampaign($campaign);
            $output->writeln('Updated campaign ' . $campaign->getSalesforceId());
        }

        return 0;
    }
}
