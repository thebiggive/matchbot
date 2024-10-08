<?php

declare(strict_types=1);

namespace MatchBot\Client;

class Fund extends Common
{
    /**
     * @param string $fundId    Salesforce ID for Champion Funding or Pledge
     * @return array Single Fund, as associative array
     * @throws NotFoundException if Fund with given ID not found
     */
    public function getById(string $fundId, bool $withCache): array
    {
        $uri = $this->getUri("{$this->getSetting('fund', 'baseUri')}/$fundId", $withCache);
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
        $response = $this->getHttpClient()->get("{$this->getSetting('campaign', 'baseUri')}/$campaignId/funds");

        if ($response->getStatusCode() !== 200) {
            throw new NotFoundException('Campaign not found');
        }

        return json_decode((string) $response->getBody(), true);
    }
}
