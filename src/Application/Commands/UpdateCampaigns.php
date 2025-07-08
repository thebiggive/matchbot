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
use MatchBot\Domain\DomainException\DomainCurrencyMustNotChangeException;
use MatchBot\Domain\FundRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'matchbot:update-campaigns')]
class UpdateCampaigns extends LockingCommand
{
    public function __construct(
        private CampaignRepository $campaignRepository,
        /** @var EntityManager|EntityManagerInterface $entityManager */
        private EntityManagerInterface $entityManager,
        private FundRepository $fundRepository,
        private LoggerInterface $logger,
        private \DateTimeImmutable $now,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setDescription(<<<EOT
Pulls down and saves the latest details of existing, already-known Campaigns from Salesforce
which were not expected to have ended over a week ago (unless --all option set, in which
case that constraint is loosened)
EOT
        );
        $this->addOption('all', null, InputOption::VALUE_NONE, 'Expands the update to ALL historic known campaigns');
    }

    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('all')) {
            /** @var Campaign[] $campaigns */
            $campaigns = $this->campaignRepository->findAll();
        } else {
            $campaigns = $this->campaignRepository->findCampaignsThatNeedToBeUpToDate();
        }

        foreach ($campaigns as $campaign) {
            $salesforceId = $campaign->getSalesforceId();

            try {
                $this->pull($campaign, $output);
            } catch (NotFoundException) {
                if (getenv('APP_ENV') === 'production') {
                    if ($campaign->getEndDate() < new \DateTime()) {
                        $this->logger->info(sprintf(
                            'Skipping unknown PRODUCTION campaign %s whose end date had passed – charity inactive?',
                            $salesforceId
                        ));
                    } else {
                        // TODO perhaps make this a warning, so it shows on dashboards but not alarm channels, and
                        // also inactivate the Campaign? While we are leaving the data as-is, warning is too noisy.
                        $this->logger->info(sprintf(
                            'Skipping unknown PRODUCTION campaign %s – charity inactive or rejected offer?',
                            $salesforceId
                        ));
                    }
                } else {
                    // Chances are a sandbox refresh has led to this campaign being deleted in the Salesforce sandbox.
                    $output->writeln('Skipping unknown sandbox campaign ' . $salesforceId);
                }
            } catch (DomainCurrencyMustNotChangeException $exception) {
                $output->writeln('Skipping invalid currency change campaign ' . $salesforceId);
            } catch (TransferException $exception) {
                $this->logger->info(sprintf(
                    'Retrying campaign %s due to transfer error "%s"',
                    $salesforceId,
                    $exception->getMessage(),
                ));

                try {
                    $this->pull($campaign, $output);
                } catch (TransferException $retryException) {
                    $transferError = sprintf(
                        'Skipping campaign %s due to 2nd transfer error "%s"',
                        $salesforceId,
                        $exception->getMessage(),
                    );
                    $output->writeln($transferError);
                    $this->logger->warning($transferError);
                }
            }
        }

        // This task is expected to bulk change lots of campaigns + funds in some cases.
        // After the loop is the most efficient time to clear the query result
        // cache so future processes see all the new data straight away.
        /**
         * @psalm-suppress DeprecatedMethod
         * @var CacheProvider $cacheDriver
         */
        $cacheDriver = $this->entityManager->getConfiguration()->getResultCacheImpl();
        $cacheDriver->deleteAll();

        return 0;
    }

    /**
     * @throws NotFoundException
     * @throws TransferException
     * @throws DomainCurrencyMustNotChangeException
     */
    protected function pull(Campaign $campaign, OutputInterface $output): void
    {
        $this->campaignRepository->updateFromSf($campaign);
        $this->fundRepository->pullForCampaign($campaign, $this->now);
        $output->writeln('Updated campaign ' . $campaign->getSalesforceId());
    }
}
