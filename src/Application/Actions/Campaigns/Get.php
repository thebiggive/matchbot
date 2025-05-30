<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Campaigns;

use GuzzleHttp\Exception\RequestException;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Environment;
use MatchBot\Client\Campaign as SfCampaignClient;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignService;
use MatchBot\Domain\Salesforce18Id;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * Gets details of a campaign. For the moment this is just a very thin wrapper around Salesforce, so that frontend
 * can start to switch over to using matchbot, but the intention is that in future
 * it should serve from the matchbot db instead of calling SF on demand.
 *
 * Not ready for use in prod.
 */
class Get extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private SfCampaignClient $salesforceCampaignClient,
        private CampaignRepository $campaignRepository,
        private CampaignService $campaignService,
        private \DateTimeImmutable $now,
    ) {
        parent::__construct($logger);
    }

    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        $sfId = Salesforce18Id::ofCampaign(
            $args['salesforceId'] ?? throw new HttpNotFoundException($request)
        );

        try {
            $campaign = $this->salesforceCampaignClient->getById($sfId->value, false);
            $this->campaignService->checkCampaignCanBeHandledByMatchbotDB($campaign, $sfId);
            return $this->respondWithData($response, $campaign);
        } catch (NotFoundException | RequestException $e) {
            $campaignMustHaveBeenUpdatedSince = Environment::current()->isLocal() ? '-10000 day' : '-1 day';
            $campaignFromMatchbotDB = $this->campaignRepository->findOneBySalesforceId(
                $sfId,
                mustBeUpdatedSince: $this->now->modify($campaignMustHaveBeenUpdatedSince)
            );

            if ($campaignFromMatchbotDB) {
                if (! \str_starts_with($sfId->value, 'XXX')) {
                    // XXX means this was a deliberate test, no need for alarm.
                    $this->logger->error("Failed to load campaign ID {$sfId} from SF, serving from Matchbot DB instead: {$e->__toString()}");
                }

                return $this->respondWithData($response, $this->campaignService->renderCampaign($campaignFromMatchbotDB));
            }
            throw new HttpNotFoundException($request);
        }
    }
}
