<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Campaigns;

use Doctrine\DBAL\Logging\DebugStack;
use GuzzleHttp\Exception\RequestException;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Environment;
use MatchBot\Client\Campaign as SfCampaignClient;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignService;
use MatchBot\Domain\MetaCampaignRepository;
use MatchBot\Domain\Salesforce18Id;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpNotFoundException;

/**
 * Gets an 'early preview' of a campaign that may not yet be complete enough to be stored into the matchbot
 * DB by proxying it from the SF API, via the domain model in memory, but *without* going via the MySQL
 * DB.
 *
 * The campaign may not yet be complete enough to save into the DB (e.g. without start and end dates);
 */
class GetEarlyPreview extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private SfCampaignClient $salesforceCampaignClient,
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

        try {
            $campaignData = $this->salesforceCampaignClient->getById($sfId->value, false);
        } catch (\MatchBot\Client\NotFoundException) {
            $this->logger->warning("Campaign not found for preview, id: " . $sfId->value);
            throw new HttpNotFoundException($request); // see ticket BG2-2919
        }
        if ($campaignData['isMetaCampaign']) {
            throw new HttpNotFoundException($request);
        }

        $charity = $this->campaignRepository->newCharityFromCampaignData($campaignData);
        $campaign = Campaign::fromSfCampaignData(
            campaignData: $campaignData,
            salesforceId: $sfId,
            charity: $charity,
            fillInDefaultValues: true, // the default values allow us to accept a campaign that e.g. doesn't have start and end dates set in SF.
        );

        $metaCampaignSlug = $campaign->getMetaCampaignSlug();
        $metaCampaign = $metaCampaignSlug ? $this->metaCampaignRepository->getBySlug($metaCampaignSlug) : null;

        return $this->respondWithData($response, ['campaign' => $this->campaignService->renderCampaign($campaign, $metaCampaign)]);
    }
}
