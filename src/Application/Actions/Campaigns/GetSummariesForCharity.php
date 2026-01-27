<?php

namespace MatchBot\Application\Actions\Campaigns;

use MatchBot\Application\Actions\Action;
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
        private \DateTimeImmutable $at,
    ) {
        parent::__construct($logger);
    }

    #[\Override] protected function action(Request $request, Response $response, array $args): Response
    {
        $sfId = Salesforce18Id::ofCharity(
            $args['charitySalesforceId'] ?? throw new HttpNotFoundException($request)
        );

        $charity = $this->charityRepository->findOneBySalesforceIdOrThrow($sfId);

        $campaigns = $this->campaignRepository->findCampaignsForCharityPage($charity, $this->at);

        return $this->respondWithData($response, [
            'charityName' => $charity->getName(),
            'campaigns' => \array_map($this->campaignService->renderCampaignSummary(...), $campaigns),
        ]);
    }
}
