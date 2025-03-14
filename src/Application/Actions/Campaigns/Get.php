<?php

namespace MatchBot\Application\Actions\Campaigns;

use MatchBot\Application\Actions\Action;
use MatchBot\Application\DummyCampaignRenderer;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Salesforce18Id;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;

class Get extends Action
{
    public function __construct(private CampaignRepository $campaignRepository,
                                LoggerInterface $logger)
    {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {
        $campaign = $this->campaignRepository->findOneBySalesforceId(Salesforce18Id::ofCampaign($args['id']));

        if ($campaign === null) {
            throw new HttpNotFoundException($request);
        }

        $campaignArray = DummyCampaignRenderer::renderCampaign($campaign);

        return $this->respondWithData($response, $campaignArray);
    }
}
