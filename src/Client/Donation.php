<?php

declare(strict_types=1);

namespace MatchBot\Client;

use GuzzleHttp\Exception\RequestException;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Domain\Salesforce18Id;

/**
 * Client to push / upsert copies of donations to Salesforce.
 */
class Donation extends Common
{
    /**
     * @return Salesforce18Id<\MatchBot\Domain\Donation>|null
     * @throws NotFoundException on missing campaign in a sandbox
     * @throws RequestException
     *
     * @psalm-suppress LessSpecificReturnStatement
     * @psalm-suppress MoreSpecificReturnType
     */
    public function createOrUpdate(DonationUpserted $message): ?Salesforce18Id
    {
        $jsonSnapshot = $message->jsonSnapshot;
        if ($jsonSnapshot === null) {
            return null;
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
