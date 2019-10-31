<?php

declare(strict_types=1);

namespace MatchBot\Client;

class Fund extends Common
{
    /**
     * @param string $fundId
     * @return array Single Fund, as associative array
     * @throws NotFoundException if Fund with given ID not found
     */
    public function getById(string $fundId): array
    {
        $response = $this->getHttpClient()->get("{$this->getSetting('fund', 'baseUri')}/$fundId");

        if ($response->getStatusCode() !== 200) {
            throw new NotFoundException('Fund not found');
        }

        return json_decode($response->getBody()->getContents(), true);
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

        return json_decode($response->getBody()->getContents(), true);
    }
}
