<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use Doctrine\Common\Cache\CacheProvider;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Exception\TransferException;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CharityRepository;
use MatchBot\Domain\DomainException\DomainCurrencyMustNotChangeException;
use MatchBot\Domain\FundRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'matchbot:update-charities',
    description: <<<EOT
            Pulls down and saves the latest details of existing, already-known Charities from Salesforce when SF has 
            told us that they are in need of update. Possibly in future could be extended to pull details of new 
            charities from Salesforce as well. Assumes there is at least one known campaign for the charity and will 
            also update that. If there is more than one campaign then any one may be updated.
            
            May be run frequently as will take almost no time when there are no charities to update - maybe as often
            as every minute or two so that someone can go straight from making a change in SF to seeing the effect
            applied in matchbot.
            EOT
)]
class UpdateCharities extends LockingCommand
{
    /**
     * @psalm-suppress PossiblyUnusedMethod - used via PHP-DI
     */
    public function __construct(
        private CampaignRepository $campaignRepository,
        private CharityRepository $charityRepository,
    ) {
        parent::__construct();
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {

        $charitiesNeedingUpdate = $this->charityRepository->findAllNeedingUpdateFromSf();

        foreach ($charitiesNeedingUpdate as $charity) {
            $campaign = $this->campaignRepository->findOneBy(['charity' => $charity->getId()]);

            if (!$campaign instanceof Campaign) {
                throw new \Exception(sprintf(
                    "No campaign found for Charity %s %s",
                    $charity->getName(),
                    $charity->getSalesforceId() ?? '(SF ID missing)'
                ));
            }

            // implicitly also updates the charity.
            $this->campaignRepository->updateFromSf($campaign);

            $output->writeln(
                <<<EOF
                    Updated campaign {$campaign->getCampaignName()} {$campaign->getSalesforceId()} and 
                    charity {$charity->getName()} {$charity->getSalesforceId()} from SF
                    
                    EOF
            );
        }

        return 0;
    }
}
