<?php

namespace MatchBot\Application\Messenger\Handler;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Environment;
use MatchBot\Application\Messenger\CharityUpdated;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\CampaignRepository;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Calls out to Salesforce to find out what exactly changed, shortly after an incoming hook/message
 * from Salesforce alerted us to a change. Gets new info for all recent-ish campaigns for now, which
 * updates Charity as a side effect. Most important for getting up to date information about Gift Aid
 * claim readiness.
 */
#[AsMessageHandler]
class CharityUpdatedHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private Environment $environment,
        private LoggerInterface $logger,
        private ContainerInterface $container, // apparently at the time this is constructed in tests
        // the container isn't ready to give us a campaignRepository, so taking a ref to the container
        // instead and getting the repository inside __invoke
    ) {
    }

    public function __invoke(CharityUpdated $message): void
    {
        $campaignRepository = $this->container->get(CampaignRepository::class);
        $this->logger->info("CharityUpdatedHandler: Handling {$message->charityAccountId->value}...");

        $sfId = $message->charityAccountId;
        $campaignsToUpdate = $campaignRepository->findUpdatableForCharity($sfId);
        $atLeastOneCampaignUpdated = false;
        foreach ($campaignsToUpdate as $campaign) {
            // also implicitly updates the charity every time.
            try {
                // We need to ensure SF tells CloudFront not to cache this particular response, or to bundle
                // it with public API calls for the same endpoint that may have been recently cached.
                $campaignRepository->updateFromSf($campaign, withCache: false);
                $atLeastOneCampaignUpdated = true;
            } catch (NotFoundException $e) {
                if ($this->environment === Environment::Production) {
                    // we don't expect to delete campaigns in prod
                    throw $e;
                }
            }
        }

        if (! $atLeastOneCampaignUpdated) {
            $this->logger->warning(
                "Could not update charity {$sfId->value} from Salesforce; no known updateable campaigns"
            );
        }

        $this->em->flush();

        $this->logger->info("CharityUpdatedHandler: Finished handling {$message->charityAccountId->value}");
    }
}
