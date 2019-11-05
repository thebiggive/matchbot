<?php

declare(strict_types=1);

namespace MatchBot\Client;

use MatchBot\Domain\Donation as DonationModel;

class Donation extends Common
{
    /**
     * @param DonationModel $donation
     * @return bool
     * @throws NotFoundException
     */
    public function create(DonationModel $donation): bool
    {
        $response = $this->getHttpClient()->post(
            $this->getSetting('donation', 'baseUri'),
            ['json' => $donation->toApiJson()]
        );

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
