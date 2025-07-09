<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use GuzzleHttp\Exception\RequestException;
use MatchBot\Application\Assertion;
use MatchBot\Application\Environment;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\FundRepository;
use MatchBot\Domain\Salesforce18Id;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'matchbot:pull-campaign-from-sf',
    description: 'Pulls an in individual campaigns) from Salesforce into the
     matchbot db. Currently intended for manuual testing on dev machines, e.g. to load a regular giving campaign before
     creating a mandate'
)]
class PullIndividualCampaignFromSF extends LockingCommand
{
    public function __construct(
        private Environment $environment,
        private CampaignRepository $campaignRepository,
        private FundRepository $fundRepository,
        private \DateTimeImmutable $now,
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
        Assertion::notEq($this->environment, Environment::Production);

        // @phpstan-ignore cast.string
        $campaignId = Salesforce18Id::ofCampaign((string) $input->getArgument('CampaignSFID'));

        $campaign = $this->campaignRepository->findOneBySalesforceId($campaignId);

        if ($campaign) {
            $this->campaignRepository->updateFromSf($campaign);
            $output->writeln("Campaign {$this->campaignFullName($campaign)} found locally, updated from SF");
        } else {
            try {
                $campaign = $this->campaignRepository->pullNewFromSf($campaignId);
            } catch (RequestException $e) {
                $output->writeln("<error>Request Exception from Salesforce, please check Campaign ID</error>");
                $output->writeln("<error>{$e->getMessage()}</error>");
                return 1;
            }

            $output->writeln("Campaign {$this->campaignFullName($campaign)} not found, pulled from SF");
        }

        $this->fundRepository->pullForCampaign($campaign, $this->now);
        $output->writeln("Fund data updated for campaign " . $this->campaignFullName($campaign));

        return 0;
    }

    private function campaignFullName(Campaign $campaign): string
    {
        return "{$campaign->getCharity()->getName()}: {$campaign->getCampaignName()}";
    }
}
