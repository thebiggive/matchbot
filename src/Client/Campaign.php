<?php

declare(strict_types=1);

namespace MatchBot\Client;

use GuzzleHttp\Exception\RequestException;
use MatchBot\Application\Assertion;

/**
 * @psalm-type SFCampaignApiResponse = array{
 *     charity: array,
 *     endDate: string,
 *     feePercentage: ?float,
 *     id: string,
 *     isMatched: bool,
 *     ready: bool,
 *     startDate: string,
 *     status: string|null,
 *     title: string,
*      currencyCode: string,
 *     }
 */

class Campaign extends Common
{
    /**
     * @param string $id
     * @return SFCampaignApiResponse Single Campaign response object as associative array
     * @throws NotFoundException if Campaign with given ID not found
     */
    public function getById(string $id, bool $withCache): array
    {
        $uri = $this->getUri("{$this->getSetting('campaign', 'baseUri')}/$id", $withCache);
        try {
            $response = $this->getHttpClient()->get($uri);
        } catch (RequestException $exception) {
            if ($exception->getResponse()?->getStatusCode() === 404) {
                // may be safely caught in sandboxes
                throw new NotFoundException(sprintf('Campaign ID %s not found in SF', $id));
            }

            // Otherwise, an unknown error occurred -> re-throw
            throw $exception;
        }

        /**
         * @var SFCampaignApiResponse $campaignResponse
         */
        $campaignResponse = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        return $campaignResponse;
    }

    /**
     * Returns a list of all campaigns associated with the meta-campagin with the given slug.
     *
     * @psalm-suppress MoreSpecificReturnType
     * @psalm-suppress LessSpecificReturnStatement
     * @psalm-suppress MixedReturnTypeCoercion
     * @return list<array>
     */
    public function findCampaignsForMetaCampaign(string $metaCampaignSlug, int $limit = 100): array
    {
        $campaigns = [];
        $encodedSlug = urlencode($metaCampaignSlug);

        $offset = 0;
        $pageSize = 100;
        $foundEmptyPage = false;
        while ($offset < $limit) {
            $uri = $this->getUri(
                "{$this->getSetting('campaign', 'baseUri')}?parentSlug=$encodedSlug&limit=$pageSize&offset=$offset",
                true
            );
            $response = $this->getHttpClient()->get($uri);

            $decoded = json_decode((string)$response->getBody(), true);

            Assertion::isArray($decoded);
            if ($decoded === []) {
                $foundEmptyPage = true;
                break;
            }

            $campaigns = [...$campaigns, ...$decoded];
            $offset += $pageSize;
        }

        if (! $foundEmptyPage) {
            throw new \Exception(
                "Did not find empty page in campaign search results, too many campaigns in metacampaign?"
            );
        }

        return $campaigns;
    }
}
