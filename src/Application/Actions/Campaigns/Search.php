<?php

namespace MatchBot\Application\Actions\Campaigns;

use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Assertion;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\CampaignService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;

/**
 * Frontend will typically default searches with a `?term` to 'relevance' order and others to 'distanceToTarget'.
 */
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
        $params = $request->getQueryParams();
        $sortField = $params['sortField'] ?? 'distanceToTarget';
        $sortDirection = $params['sortDirection'] ?? 'desc';
        /** @var ?string $term */
        $term = $params['term'] ?? null;
        /** @var ?string $country */
        $country = $params['country'] ?? null;
        Assertion::string($sortDirection);
        Assertion::string($sortField);
        Assertion::nullOrString($term);

        if (!\in_array($sortDirection, ['asc', 'desc'], true)) {
            throw new HttpBadRequestException($request, 'Unrecognised sort direction');
        }

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
                default:
                    // No other params apply a filter using the JSON `salesforceData` field.
            }
        }

        /** @var ?string $parentSlug */
        $parentSlug = $params['parentSlug'] ?? null;
        Assertion::nullOrString($parentSlug);

        /** @var ?string $fundSlug */
        $fundSlug = $params['fundSlug'] ?? null;
        Assertion::nullOrString($fundSlug);

        // Use limit 100 if a higher value requested.
        $limit = min(100, (int) ($params['limit'] ?? 20));

        try {
            /** @psalm-suppress ArgumentTypeCoercion */
            $campaigns = $this->campaignRepository->search(
                sortField: $sortField, // @mago-expect analysis:possibly-invalid-argument (search function will throw safely if given invalid arg)
                sortDirection: $sortDirection,
                offset: (int)($params['offset'] ?? 0),
                limit: $limit,
                metaCampaignSlug: $parentSlug,
                fundSlug: $fundSlug,
                jsonMatchInListConditions: $jsonMatchInListConditions,
                term: $term,
                country: $country,
            );
        } catch (\InvalidArgumentException $exception) {
            throw new HttpBadRequestException($request, $exception->getMessage(), $exception);
        }

        /**
         * Some campaigns have SF data {} when they were last synced before we saved full SF data. If we try
         * to render those there are missing array keys for beneficiaries et al.
         *
         * Have to then pass through array_values to make sure it produces a JSON array as needed by FE not a JSON
         * object - any missing keys (other than at the end of the list) will make PHP output it as an object.
         */
        return
            \array_filter($campaigns, static fn(Campaign $c) => ! $c->isSfDataMissing())
            |> \array_values(...)
            |> (fn(array $campaignsWithSfData) => \array_map($this->campaignService->renderCampaignSummary(...), $campaignsWithSfData))
            |> (fn(array $campaignSummaries) => new JsonResponse(['campaignSummaries' => $campaignSummaries], 200));
    }
}
