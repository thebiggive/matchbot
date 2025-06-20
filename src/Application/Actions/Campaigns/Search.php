<?php

namespace MatchBot\Application\Actions\Campaigns;

use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Assertion;
use MatchBot\Application\Environment;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;

class Search extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private CampaignRepository $campaignRepository,
        private CampaignService $campaignService,
    ) {
        parent::__construct($logger);
    }

    #[\Override] protected function action(Request $request, Response $response, array $args): Response
    {
        // @todo Possibly not safe to expose yet.
        Assertion::notSame(Environment::current(), Environment::Production);

        $params = $request->getQueryParams();
        $sortField = $params['sortField'] ?? '';
        $sortDirection = $params['sortDirection'] ?? 'desc';
        Assertion::string($sortDirection);
        Assertion::string($sortField);

        if (!\in_array($sortDirection, ['asc', 'desc'], true)) {
            throw new HttpBadRequestException($request, 'Unrecognised sort direction');
        }

        $campaigns = $this->campaignRepository->search(
            sortField: $sortField,
            sortDirection: $sortDirection,
            offset: (int) ($params['offset'] ?? 0),
            limit: (int) ($params['limit'] ?? 20),
        );

        // TODO performant summaries – most notably `amountRaised` and `matchFundsRemaining` should
        // come from future stats table.
        $campaignSummaries = \array_map($this->campaignService->renderCampaignSummary(...), $campaigns);

        return new JsonResponse($campaignSummaries, 200);
    }
}
