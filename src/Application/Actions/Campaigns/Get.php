<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Campaigns;

use MatchBot\Application\Actions\Action;
use MatchBot\Application\Assertion;
use MatchBot\Application\Environment;
use MatchBot\Client\Campaign as SfCampaignClient;
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
    ) {
        parent::__construct($logger);
    }

    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        if (Environment::current()->isProduction()) {
            throw new HttpNotFoundException($request);
        }

        $sfId = $args['salesforceId'] ?? throw new HttpNotFoundException($request);

        $campaign = $this->salesforceCampaignClient->getById($sfId, false);

        return $this->respondWithData($response, $campaign);
    }
}
