<?php

declare(strict_types=1);

namespace MatchBot\Client;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\Salesforce18Id;

/**
 * Client to push / upsert copies of donations to Salesforce.
 */
class Donation extends Common
{
    /**
     * @throws BadRequestException
     * @throws BadResponseException
     * @throws NotFoundException on missing campaign in a sandbox
     * @throws GuzzleException
     */
    public function createOrUpdate(DonationUpserted $message): Salesforce18Id
    {
        $jsonSnapshot = $message->jsonSnapshot;

        // next lines should only be needed to fix some missing data in existing messages in queue that
        // are not able to be pushed to salesforce as SF code is throwing an NPE missing this.
        // Can be deleted immediately after deployment.
        if (! array_key_exists('confirmationByMatchbot', $jsonSnapshot)) {
            $jsonSnapshot['confirmationByMatchbot'] = false;
        }

        return $this->postUpdateToSalesforce(
            $this->baseUri() . '/' . $message->uuid,
            $jsonSnapshot,
            $message->uuid,
            'donation',
        );
    }

    private function baseUri(): string
    {
        return $this->sfApiBaseUrl . '/donations/services/apexrest/v2.0/donations';
    }
}
