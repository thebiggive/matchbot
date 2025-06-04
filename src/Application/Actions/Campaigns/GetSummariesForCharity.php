<?php

namespace MatchBot\Application\Actions\Campaigns;

use MatchBot\Application\Actions\Action;
use MatchBot\Application\Assertion;
use MatchBot\Application\Environment;
use MatchBot\Application\HttpModels\Charity;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignService;
use MatchBot\Domain\CharityRepository;
use MatchBot\Domain\Salesforce18Id;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;

use const true;

/**
 * Returns a list of 'campaign summary' records for all the charity campaigns that we should show for
 * any given charity
 */
class GetSummariesForCharity extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private CharityRepository $charityRepository,
         private CampaignRepository $campaignRepository,
        private CampaignService $campaignService,
    ) {
        parent::__construct($logger);
    }

    #[\Override] protected function action(Request $request, Response $response, array $args): Response
    {
        $sfId = Salesforce18Id::ofCharity(
            $args['charitySalesforceId'] ?? throw new HttpNotFoundException($request)
        );

        $charity = $this->charityRepository->findOneBySfIDOrThrow($sfId);

        $campaigns = $this->campaignRepository->findCampaignsForCharityPage($charity);

        return $this->respondWithData($response, [
            'charityName' => $charity->getName(),
            'campaigns' => \array_map($this->campaignService->renderCampaignSummary(...), $campaigns),
        ]);
    }
}
