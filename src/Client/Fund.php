<?php

declare(strict_types=1);

namespace MatchBot\Client;

use GuzzleHttp\Exception\RequestException;
use MatchBot\Application\Environment;
use MatchBot\Application\Messenger\FundTotalUpdated;
use Psr\Log\LogLevel;

/**
 * @psalm-type fundArray array{
 *      currencyCode: string,
 *      id: string,
 *      name: ?string,
 *      slug: ?string,
 *      type: string,
 *      amountForCampaign: string|null|numeric,
 *      isShared: boolean,
 *  }
 */
class Fund extends Common
{
    use HashTrait;

    /**
     * @param string $fundId    Salesforce ID for Champion Funding or Pledge
     * @return fundArray Single Fund, as associative array
     * @throws NotFoundException if Fund with given ID not found
     *
     * @psalm-suppress PossiblyUnusedMethod - been unuesed for some time, may be wanted in future.
     */
    public function getById(string $fundId, bool $withCache): array
    {
        $uri = $this->getUri($this->fundBaseUri() . $fundId, $withCache);
        $response = $this->getHttpClient()->get($uri);

        if ($response->getStatusCode() !== 200) {
            throw new NotFoundException('Fund not found');
        }

        /** @var fundArray $fund */
        $fund = json_decode((string)$response->getBody(), true);
        return $fund;
    }

    /**
     * @param string $campaignId
     * @return array<fundArray> funds
     * @throws NotFoundException if Campaign with given ID not found
     * @psalm-suppress PossiblyUnusedMethod
     */
    public function getForCampaign(string $campaignId): array
    {
        $uri = $this->campaignsBaseURI() . "$campaignId/funds";

        $response = $this->getHttpClient()->get($uri);

        if ($response->getStatusCode() !== 200) {
            throw new NotFoundException('Campaign not found');
        }

        /** @var array<fundArray> $funds */
        $funds = json_decode((string)$response->getBody(), true);

        return $funds;
    }

    public function pushAmountAvailable(FundTotalUpdated $fundMessage): void
    {
        $uri = $this->getUri(
            uri: $this->fundBaseUri() . $fundMessage->salesforceId,
            withCache: false,
        );
        $jsonSnapshot = $fundMessage->jsonSnapshot;
        $encodedJson = \json_encode($jsonSnapshot, \JSON_THROW_ON_ERROR);

        try {
            $this->getHttpClient()->put($uri, [
                'json' => $jsonSnapshot,
                'headers' => $this->getVerifyHeaders(json_encode($jsonSnapshot, \JSON_THROW_ON_ERROR)),
            ]);
        } catch (RequestException $exception) {
            // In the case of Staging -> Full, we have two error scenarios we want to mostly ignore for
            // now:
            // 1. Entirely missing funds (which 404) – this is due to sandbox refresh not always having
            //    an accompanying full MatchBot data reset.
            // 2. Mismatched fund totals (which 500) – this is due to some but not all campaigns not
            //    being in MatchBot in the first place, which is the case for a handful of e.g. GMF25
            //    funds with one or more campaigns whose charities have still not reached a campaign
            //    ready state.
            $logLevel = Environment::current()->isProduction() ? LogLevel::ERROR : LogLevel::INFO;
            $this->logger->log(
                $logLevel,
                sprintf(
                    'Failed to push amount available for fund %s. Got %s: %s. Data snapshot: %s',
                    $fundMessage->salesforceId,
                    $exception->getCode(),
                    $exception->getMessage(),
                    $encodedJson,
                ),
            );

            return;
        }

        $this->logger->info("Pushed amount available for fund: {$fundMessage->salesforceId}: Snapshot: $encodedJson");
    }

    public function fundBaseUri(): string
    {
        return "{$this->sfApiBaseUrl}/funds/services/apexrest/v1.0/funds/";
    }

    public function campaignsBaseURI(): string
    {
        return "{$this->sfApiBaseUrl}/campaigns/services/apexrest/v1.0/campaigns/";
    }
}
