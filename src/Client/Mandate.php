<?php

declare(strict_types=1);

namespace MatchBot\Client;

use GuzzleHttp\Exception\RequestException;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Application\Messenger\MandateUpserted;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\Salesforce18Id;

/**
 * Client to push / upsert copies of Regular Giving mandates to Salesforce.
 */
class Mandate extends Common
{
    use HashTrait;

    /**
     * @throws NotFoundException on missing campaign in a sandbox
     * @throws BadRequestException
     */
    public function createOrUpdate(MandateUpserted $message): Salesforce18Id
    {
        return $this->postUpdateToSalesforce(
            $this->baseUri() . $message->uuid,
            $message->jsonSnapshot,
            $message->uuid,
            'mandate',
        );
    }

    private function baseUri(): string
    {
        return $this->sfApiBaseUrl . '/donations/services/apexrest/v1.0/mandates/';
    }
}
