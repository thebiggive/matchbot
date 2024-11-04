<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Assertion;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
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
    public function __construct(private CampaignRepository $campaignRepository)
    {
        parent::__construct();
    }
    public function configure(): void
    {
        $this->addArgument('metaCampaignSlug', InputArgument::REQUIRED);
    }
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        // Steps:
        // 1. Use SF API to get list of campaigns withi meta campaign by hitting e.g.
        // https://sf-api-production.thebiggive.org.uk/campaigns/services/apexrest/v1.0/campaigns?limit=6&parentSlug=christmas-challenge-2024
        // https://{sf-api-base}}/campaigns/services/apexrest/v1.0/campaigns?limit=6&parentSlug={slug}}
        //
        // Iterate through each campaign. Check if we already have it in DB. If not call
        // \MatchBot\Domain\CampaignRepository::pullNewFromSf .
        // If so call \MatchBot\Domain\SalesforceReadProxyRepository::updateFromSf
        // output counts of how many campaigns and charities newly pulled and/or updated.

        $metaCampaginSlug = $input->getArgument('metaCampaignSlug');
        \assert(is_string($metaCampaginSlug));
        Assertion::betweenLength($metaCampaginSlug, minLength: 5, maxLength: 50);
        Assertion::regex($metaCampaginSlug, '/^[A-Za-z0-9-]+$/');

        $this->campaignRepository->fetchAllForMetaCampaign($metaCampaginSlug);


        return 1; // implementation not done yet.
    }
}
