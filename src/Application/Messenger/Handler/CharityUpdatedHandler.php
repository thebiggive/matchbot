<?php

namespace MatchBot\Application\Messenger\Handler;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Environment;
use MatchBot\Application\Messenger\CharityUpdated;
use MatchBot\Client;
use MatchBot\Client\CampaignNotReady;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Calls out to Salesforce to find out what exactly changed, shortly after an incoming hook/message
 * from Salesforce alerted us to a change. Gets new info for all recent-ish campaigns for now, which
 * updates Charity as a side effect. Most important for getting up to date information about Gift Aid
 * claim readiness.
 */
#[AsMessageHandler]
readonly class CharityUpdatedHandler
{
    public function __construct(
        private Client\Campaign $campaignClient,
        private EntityManagerInterface $em,
        private Environment $environment,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(CharityUpdated $message): void
    {
        $this->logger->info("CharityUpdatedHandler: Handling {$message->charityAccountId->value}...");

        $campaignRepository = $this->getCampaignRepository();
        $sfId = $message->charityAccountId;
        $campaignsToUpdate = $campaignRepository->findUpdatableForCharity($sfId);
        $atLeastOneCampaignUpdated = false;
        foreach ($campaignsToUpdate as $campaign) {
            // also implicitly updates the charity every time.
            try {
                $campaignRepository->updateFromSf($campaign);
                $atLeastOneCampaignUpdated = true;
            } catch (NotFoundException $e) {
                if ($this->environment === Environment::Production) {
                    // we don't expect to delete campaigns in prod
                    throw $e;
                }
            } catch (CampaignNotReady $e) {
                // but it is possible for campaigns in prod to go from ready to not ready, e.g. if a charity's
                // Stripe status changes or they unexpectedly withdraw late
                $this->logger->warning("{$e->getMessage()} while updating charity {$sfId->value}");
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

    /**
     * Injecting campaign repo directly seems to cause a DI problem in combination with registering
     * this handler in bus's HandlersLocator. Working around by setting up locally for the handler here
     * for now.
     */
    private function getCampaignRepository(): CampaignRepository
    {
        /** @var CampaignRepository $campaignRepository */
        $campaignRepository = $this->em->getRepository(Campaign::class);
        $campaignRepository->setClient($this->campaignClient);
        $campaignRepository->setLogger($this->logger);

        return $campaignRepository;
    }
}
