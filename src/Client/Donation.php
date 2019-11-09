<?php

declare(strict_types=1);

namespace MatchBot\Client;

use MatchBot\Domain\Donation as DonationModel;

class Donation extends Common
{
    /**
     * @param DonationModel $donation
     * @return string Salesforce donation ID
     * @throws BadRequestException
     */
    public function create(DonationModel $donation): string
    {
        $response = $this->getHttpClient()->post(
            $this->getSetting('donation', 'baseUri'),
            ['json' => $donation->toApiModel()]
        );

        if ($response->getStatusCode() !== 200) {
            throw new BadRequestException('Donation not created');
        }

        $donationCreatedResponse = json_decode($response->getBody()->getContents(), true);

        return $donationCreatedResponse['donation']['donationId'];
    }
}
