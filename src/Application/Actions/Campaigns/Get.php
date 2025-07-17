<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Campaigns;

use GuzzleHttp\Exception\TransferException;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Environment;
use MatchBot\Client\Campaign as SfCampaignClient;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignService;
use MatchBot\Domain\MetaCampaignRepository;
use MatchBot\Domain\Salesforce18Id;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * Gets details of a campaign. For the moment this is just a very thin wrapper around Salesforce, so that frontend
 * can start to switch over to using matchbot, but the intention is that in future
 * it should serve from the matchbot db instead of calling SF on demand.
 */
class Get extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private SfCampaignClient $salesforceCampaignClient,
        private CampaignRepository $campaignRepository,
        private MetaCampaignRepository $metaCampaignRepository,
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

        if (Environment::current()->isProduction()) {
            // In prod the campaigns in the MySQL database are not yet complete, so for now we try to serve from SF first.
            // Hopefully in a few days that will be complete and we can delete this block and just keep the else block.

            try {
                $campaign = $this->salesforceCampaignClient->getById($sfId->value, false);
                if ($campaign['isMetaCampaign']) {
                    // throwing manually for now. When MAT-405 is done and we serve only from matchbot DB metcampaigns will not be in the same
                    // DB table so this sort of request will naturally generate a 404 response.
                    $this->logger->warning("Metacampaign requested by ID {$sfId->value}, should request via slug {$campaign['slug']} at dedicated metacampaign page instead");
                    throw new HttpNotFoundException($request);
                }
                $this->campaignService->checkCampaignCanBeHandledByMatchbotDB($campaign, $sfId);

                // Temporarily replace championName for 8 SCW25 campaigns.
                $greggsCampaignIds = [
                    'a05WS000004MEy5YAG',
                    'a05WS000004HasnYAC',
                    'a05WS000004PumJYAS',
                    'a05WS000004GkCfYAK',
                    'a05WS000004aiMnYAI',
                    'a05WS000004EqZ7YAK',
                    'a05WS000004ZLAXYA4',
                    'a05WS000004P41JYAS',
                ];
                if (in_array($campaign['id'], $greggsCampaignIds, true)) {
                    // this code will be deleted when MAT-405 is done so there is a copy of the same
                    // thing in \MatchBot\Domain\CampaignService::renderCampaign which we're intending to keep.
                    $campaign['championName'] = 'Greggs Foundation';
                }

                return $this->respondWithData($response, $campaign);
            } catch (NotFoundException | TransferException  $e) { // TransferException includes ConnectException, RequestException, etc.
                $campaignMustHaveBeenUpdatedSince = Environment::current()->isLocal() ? '-10000 day' : '-1 day';
                $campaign = $this->campaignRepository->findOneBySalesforceId(
                    $sfId,
                    mustBeUpdatedSince: $this->now->modify($campaignMustHaveBeenUpdatedSince)
                );

                if ($campaign) {
                    if (!\str_starts_with(\strtolower($sfId->value), 'xxx')) {
                        // xxx means this was a deliberate test, no need for alarm.
                        $this->logger->warning("Failed to load campaign ID {$sfId} from SF, serving from Matchbot DB instead: {$e->__toString()}");
                    }

                    $slug = $campaign->getMetaCampaignSlug();
                    if ($slug !== null) {
                        $metaCampaign = $this->metaCampaignRepository->getBySlug($slug);
                    } else {
                        $metaCampaign = null;
                    }

                    return $this->respondWithData(
                        $response,
                        $this->campaignService->renderCampaign(campaign: $campaign, metaCampaign: $metaCampaign)
                    );
                }
                throw new HttpNotFoundException($request);
            }
        } else {
            $campaign = $this->campaignRepository->findOneBySalesforceId($sfId);

            if (!$campaign) {
                throw new HttpNotFoundException($request);
            }

            $slug = $campaign->getMetaCampaignSlug();
            if ($slug !== null) {
                $metaCampaign = $this->metaCampaignRepository->getBySlug($slug);
            } else {
                $metaCampaign = null;
            }

            return $this->respondWithData(
                $response,
                $this->campaignService->renderCampaign(campaign: $campaign, metaCampaign: $metaCampaign)
            );
        }
    }
}
