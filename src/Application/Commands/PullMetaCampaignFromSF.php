<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Client\Campaign as CampaignClient;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\FundRepository;
use MatchBot\Domain\MetaCampaign;
use MatchBot\Domain\MetaCampaignRepository;
use MatchBot\Domain\MetaCampaignSlug;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 *
 */
#[AsCommand(
    name: 'matchbot:pull-meta-campaign-from-sf',
    description: 'Pulls a meta campaign (or at least all its related individual campaigns) from Salesforce into the
     matchbot db. Should improve performance and reduce chance of any db contention particularly if run shortly before 
     campaign start time'
)]
class PullMetaCampaignFromSF extends LockingCommand
{
    public function __construct(
        private CampaignRepository $campaignRepository,
        private MetaCampaignRepository $metaCampaignRepository,
        private FundRepository $fundRepository,
        private EntityManagerInterface $entityManager,
        private CampaignClient $campaignClient,
        private DateTimeImmutable $now,
    ) {
        parent::__construct();
    }
    #[\Override]
    public function configure(): void
    {
        $this->addArgument('metaCampaignSlug', InputArgument::REQUIRED);
    }

    public function pullCharityCampaigns(MetaCampaignSlug $metaCampaginSlug, OutputInterface $output): void
    {
        ['newFetchCount' => $newFetchCount, 'updatedCount' => $updatedCount, 'campaigns' => $campaigns] =
            $this->campaignRepository->fetchAllForMetaCampaign($metaCampaginSlug);

        $total = $newFetchCount + $updatedCount;

        $i = 0;
        foreach ($campaigns as $campaign) {
            $i++;
            $output->writeln("Pulling funds for ($i of $total) '{$campaign->getCampaignName()}'");
            $this->fundRepository->pullForCampaign($campaign, $this->now);
        }

        $output->writeln("Fetched $total campaigns total from Salesforce for '$metaCampaginSlug->slug'");
        $output->writeln("$newFetchCount new campaigns added to DB, $updatedCount campaigns updated");
    }

    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $input->getArgument('metaCampaignSlug');
        \assert(is_string($slug));
        $slug = MetaCampaignSlug::of($slug);

        $existingMetaCampaignInDB = $this->metaCampaignRepository->getBySlug($slug);
        $data = $this->campaignClient->getBySlug($slug);

        if (\is_null($existingMetaCampaignInDB)) {
            $metaCampaign = MetaCampaign::fromSfCampaignData($slug, $data);
            $metaCampaign->setSalesforceLastPull(\DateTime::createFromInterface($this->now));
            // create new one from SF data
            $this->entityManager->persist($metaCampaign);
        } else {
            // todo update existing from SF data
        }

        $this->entityManager->flush();

        $this->pullCharityCampaigns($slug, $output);

        return 0;
    }
}
