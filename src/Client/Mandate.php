<?php

declare(strict_types=1);

namespace MatchBot\Client;

use GuzzleHttp\Exception\GuzzleException;
use MatchBot\Application\Messenger\MandateUpserted;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\Salesforce18Id;

/**
 * Client to push / upsert copies of Regular Giving mandates to Salesforce.
 */
class Mandate extends Common
{
    use HashTrait;

    /**
     * @throws BadRequestException
     * @throws BadResponseException
     * @throws NotFoundException on missing campaign in a sandbox
     * @throws GuzzleException
     *
     * @psalm-suppress LessSpecificReturnStatement
     * @psalm-suppress MoreSpecificReturnType
     *
     * @return Salesforce18Id<RegularGivingMandate>
     */
    public function createOrUpdate(MandateUpserted $message): Salesforce18Id
    {
        // We have both donations and mandates in the URI because in SF regular giving mandates
        // are part of the donations API 'Site'. URI for a POST does not depend on content of mandate - mandate
        // is identified by the URI in the request body.
        $uri = $this->sfApiBaseUrl . '/donations/services/apexrest/v1.0/mandates/';

        return $this->postUpdateToSalesforce(
            $uri,
            $message->jsonSnapshot,
            $message->uuid,
            'mandate',
        );
    }
}
