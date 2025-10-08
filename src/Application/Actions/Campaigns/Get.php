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
 * Gets details of a campaign.
 */
class Get extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private CampaignRepository $campaignRepository,
        private MetaCampaignRepository $metaCampaignRepository,
        private CampaignService $campaignService,
    ) {
        parent::__construct($logger);
    }

    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        $sfId = Salesforce18Id::ofCampaign(
            $args['salesforceId'] ?? throw new HttpNotFoundException($request)
        );

        $campaign = $this->campaignRepository->findOneBySalesforceId($sfId);

        if (!$campaign) {
            throw new HttpNotFoundException($request);
        }

        if ($campaign->isSfDataMissing()) {
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
