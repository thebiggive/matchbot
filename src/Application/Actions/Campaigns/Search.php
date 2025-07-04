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
        /** @var 'Active'|'Expired'|'Preview'|null */
        $status = $params['status'] ?? null;
        /** @var ?string */
        $term = $params['term'] ?? null;
        Assertion::string($sortDirection);
        Assertion::string($sortField);
        Assertion::inArray($status, ['Active','Expired','Preview', null]);
        Assertion::nullOrString($term);

        if (!\in_array($sortDirection, ['asc', 'desc'], true)) {
            throw new HttpBadRequestException($request, 'Unrecognised sort direction');
        }

        // @todo fund slug – have to join when set, and also first start storing in Fund table?

        $jsonMatchOneConditions = [];
        $jsonMatchInListConditions = [];
        foreach ($params as $key => $value) {
            switch ($key) {
                case 'beneficiary':
                    Assertion::string($value);
                    $jsonMatchInListConditions['beneficiaries'] = $value;
                    break;
                case 'category':
                    Assertion::string($value);
                    $jsonMatchInListConditions['categories'] = $value;
                    break;
                case 'country':
                    Assertion::string($value);
                    $jsonMatchInListConditions['countries'] = $value;
                    break;
                case 'parent':
                case 'parentSlug':
                    Assertion::string($value);
                    $jsonMatchOneConditions['parentRef'] = $value;
                    break;
                default:
                    // No other params apply a filter using the JSON `salesforceData` field.
            }
        }

        // Use limit 100 if a higher value requested.
        $limit = min(100, (int) ($params['limit'] ?? 20));
        $campaigns = $this->campaignRepository->search(
            sortField: $sortField,
            sortDirection: $sortDirection,
            offset: (int) ($params['offset'] ?? 0),
            limit: $limit,
            status: $status,
            jsonMatchOneConditions: $jsonMatchOneConditions,
            jsonMatchInListConditions: $jsonMatchInListConditions,
            term: $term,
        );

        /**
         * Some campaigns have SF data {} when they were last synced before we saved full SF data. If we try
         * to render those there are missing array keys for beneficiaries et al.
         * @psalm-suppress RedundantCondition For charity only empty SF data; we'll soon load all campaign data.
         */
        $campaignsWithSfData = array_filter($campaigns, static function ($campaign) {
            $coreCampaignData = $campaign->getSalesforceData();
            unset($coreCampaignData['charity']);
            return $coreCampaignData !== [];
        });
        $campaignSummaries = \array_map($this->campaignService->renderCampaignSummary(...), $campaignsWithSfData);

        return new JsonResponse($campaignSummaries, 200);
    }
}
