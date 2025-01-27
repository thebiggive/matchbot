<?php

declare(strict_types=1);

namespace MatchBot\Client;

use MatchBot\Application\Messenger\FundTotalUpdated;

class Fund extends Common
{
    use HashTrait;

    /**
     * @param string $fundId    Salesforce ID for Champion Funding or Pledge
     * @return array Single Fund, as associative array
     * @throws NotFoundException if Fund with given ID not found
     */
    public function getById(string $fundId, bool $withCache): array
    {
        $uri = $this->getUri($this->fundBaseUri() . $fundId, $withCache);
        $response = $this->getHttpClient()->get($uri);

        if ($response->getStatusCode() !== 200) {
            throw new NotFoundException('Fund not found');
        }

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * @param string $campaignId
     * @return array Array of Funds, each as associative array
     * @throws NotFoundException if Campaign with given ID not found
     */
    public function getForCampaign(string $campaignId): array
    {
        $uri = $this->campaignsBaseURI() . "$campaignId/funds";

        $response = $this->getHttpClient()->get($uri);

        if ($response->getStatusCode() !== 200) {
            throw new NotFoundException('Campaign not found');
        }

        return json_decode((string) $response->getBody(), true);
    }

    public function pushAmountAvailable(FundTotalUpdated $fundMessage): void
    {
        $uri = $this->getUri(
            uri: $this->fundBaseUri() . $fundMessage->salesforceId,
            withCache: false,
        );
        $this->getHttpClient()->put($uri, [
            'json' => $fundMessage->jsonSnapshot,
            'headers' => $this->getVerifyHeaders(json_encode($fundMessage->jsonSnapshot, \JSON_THROW_ON_ERROR)),
        ]);
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
