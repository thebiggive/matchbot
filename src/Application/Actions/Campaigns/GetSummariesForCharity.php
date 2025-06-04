<?php

namespace MatchBot\Application\Actions\Campaigns;

use MatchBot\Application\Actions\Action;
use MatchBot\Application\Assertion;
use MatchBot\Application\Environment;
use MatchBot\Application\HttpModels\Charity;
use MatchBot\Domain\CampaignRepository;
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
        // private CampaignRepository $campaignRepository,
    ) {
        parent::__construct($logger);
    }

    /**
     * @return array<mixed>
     */
    public function dummyCampaignSummary(bool $isMatched, string $amountRaised, string $currencyCode, string $name): array
    {
        Assertion::false(Environment::current()->isProduction());

        return [
            'charity' => [
                'id' => '123456789012345678',
                'name' => 'charity name',
            ],
            'percentRaised' => 3,
            'isRegularGiving' => false,
            'id' => '123456789012345678',
            'amountRaised' => $amountRaised,
            'beneficiaries' => ['me', 'you', 'him'],
            'categories' => ['some category'],
            'championName' => 'Mrs champion',
            'currencyCode' => $currencyCode,
            'endDate' => '2070-01-01T00:00:00.000Z',
            'imageUri' => '',
            'isMatched' => $isMatched,
            'matchFundsRemaining' => 3,
            'startDate' => '2070-01-01T00:00:00.000Z',
            'status' => 'Active',
            'target' => 3,
            'title' => $name,
        ];
    }

    #[\Override] protected function action(Request $request, Response $response, array $args): Response
    {
        $sfId = Salesforce18Id::ofCharity(
            $args['charitySalesforceId'] ?? throw new HttpNotFoundException($request)
        );

        $charity = $this->charityRepository->findOneBySfIDOrThrow($sfId);

        return $this->respondWithData($response, [
            'charityName' => $charity->getName(),
            // todo - replace following with real list of campaigns
            'campaigns' => $this->getDummyCampaignSummaryList(),
        ]);
    }

    /** @return list<mixed> */
    private function getDummyCampaignSummaryList(): array
    {
        return [
            $this->dummyCampaignSummary(true, '300', 'GBP', 'Campaign Name'),
            $this->dummyCampaignSummary(false, '2', 'USD', 'Another campaign'),
            $this->dummyCampaignSummary(true, '99_99', 'GBP', 'z'),

            $this->dummyCampaignSummary(false, '20', 'GBP', '🤷'),
            $this->dummyCampaignSummary(true, '5_000_000', 'GBP', '🤷🏽'),
            $this->dummyCampaignSummary(false, '0', 'GBP', '🤷🏿‍♀️'),

        ];
    }
}
