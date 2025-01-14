<?php

declare(strict_types=1);

namespace MatchBot\Client;

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
     * @throws NotFoundException on missing campaign in a sandbox
     * @throws BadRequestException
     */
    public function createOrUpdate(DonationUpserted $message): Salesforce18Id
    {
        return $this->postUpdateToSalesforce(
            $this->baseUri() . '/' . $message->uuid,
            $message->jsonSnapshot,
            $message->uuid,
            'donation',
        );
    }

    protected function baseUri(): string
    {
        return $this->sfApiBaseUrl . '/donations/services/apexrest/v1.0/donations';
    }
}
