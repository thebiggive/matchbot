<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\FundRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

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
    /**
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function __construct(
        private CampaignRepository $campaignRepository,
        private FundRepository $fundRepository
    ) {
        parent::__construct();
    }
    #[\Override]
    public function configure(): void
    {
        $this->addArgument('metaCampaignSlug', InputArgument::REQUIRED);
    }
    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $metaCampaginSlug = $input->getArgument('metaCampaignSlug');
        \assert(is_string($metaCampaginSlug));
        Assertion::betweenLength($metaCampaginSlug, minLength: 5, maxLength: 50);
        Assertion::regex($metaCampaginSlug, '/^[A-Za-z0-9-]+$/');

        ['newFetchCount' => $newFetchCount, 'updatedCount' => $updatedCount, 'campaigns' => $campaigns] =
            $this->campaignRepository->fetchAllForMetaCampaign($metaCampaginSlug);

        $total = $newFetchCount + $updatedCount;

        $i = 0;
        foreach ($campaigns as $campaign) {
            $i++;
            $output->writeln("Pulling funds for ($i of $total) '{$campaign->getCampaignName()}'");
            $this->fundRepository->pullForCampaign($campaign);
        }

        $output->writeln("Fetched $total campaigns total from Salesforce for '$metaCampaginSlug'");
        $output->writeln("$newFetchCount new campaigns added to DB, $updatedCount campaigns updated");

        return 0;
    }
}
