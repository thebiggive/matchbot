<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Doctrine\Common\Cache\CacheProvider;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\FundRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateCampaigns extends LockingCommand
{
    protected static $defaultName = 'matchbot:update-campaigns';

    private CampaignRepository $campaignRepository;
    /** @var EntityManager|EntityManagerInterface $entityManager */
    private EntityManagerInterface $entityManager;
    private FundRepository $fundRepository;

    public function __construct(
        CampaignRepository $campaignRepository,
        EntityManagerInterface $entityManager,
        FundRepository $fundRepository
    )
    {
        $this->campaignRepository = $campaignRepository;
        $this->entityManager = $entityManager;
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
            try {
                $this->campaignRepository->pull($campaign);
                $this->fundRepository->pullForCampaign($campaign);
                $output->writeln('Updated campaign ' . $campaign->getSalesforceId());
            } catch (NotFoundException $exception) {
                if (getenv('APP_ENV') === 'production') {
                    throw $exception;
                }

                // Chances are a sandbox refresh has led to this campaign being deleted in the Salesforce sandbox.
                $output->writeln('Skipping unknown sandbox campaign ' . $campaign->getSalesforceId());
            }
        }

        // This task is expected to bulk change lots of campaigns + funds in some cases.
        // After the loop is the most efficient time to clear the query result
        // cache so future processes see all the new data straight away.
        /** @var CacheProvider $cacheDriver */
        $cacheDriver = $this->entityManager->getConfiguration()->getResultCacheImpl();
        $cacheDriver->deleteAll();

        return 0;
    }
}
