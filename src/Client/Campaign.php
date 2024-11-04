<?php

declare(strict_types=1);

namespace MatchBot\Client;

use GuzzleHttp\Exception\RequestException;
use MatchBot\Application\Assertion;
use MatchBot\Domain\Salesforce18Id;

class Campaign extends Common
{
    /**
     * @param string $id
     * @return array Single Campaign response object as associative array
     * @throws NotFoundException if Campaign with given ID not found
     * @throws CampaignNotReady if campaign found in SF but not ready for use in matchbot.
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
         * @var array{status: string, ready: bool} $campaignResponse
         * (other properties exist and are needed but not documented here yet.)
         */
        $campaignResponse = json_decode((string)$response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (!$campaignResponse['ready']) {
            throw new CampaignNotReady(sprintf(
                'Campaign ID %s not ready to pull to matchbot, status: %s',
                $id,
                $campaignResponse['status'] ?? 'no_status'
            ));
        }

        return $campaignResponse;
    }

    /**
     * Returns a list of all campaigns associated with the meta-campagin with the given slug.
     *
     * @todo - consider how to fetch more than 100. Currently not possible with this method as SF API has a built
     * in max limit of 100 at https://github.com/thebiggive/salesforce/blob/62f7aec4bc8c6c0463f75aab379ac97185b4693c/force-app/main/default/classes/CampaignSearchService.cls#L37
     * but also calling the search api as here when all we want is the ID is inefficient. So I think we'll need we'll
     * need either a new API function in SF to give us just IDs, or go the other way and have a new API function to give
     * us all the data we need to pull into matchbot for many campaigns at once.
     *
     * In principle, we could optimise further by not repeating the charity but in practice we don't expect the same
     * charity to have multiple campaigns within a single metacampaign anyway.
     *
     * @psalm-suppress MoreSpecificReturnType
     * @psalm-suppress LessSpecificReturnStatement
     * @psalm-suppress MixedReturnTypeCoercion
     * @return list<array>
     */
    public function findCampaignsForMetaCampaign(string $metaCampaignSlug, int $limit = 100): array
    {
        $encodedSlug = urlencode($metaCampaignSlug);
        $uri = $this->getUri(
            "{$this->getSetting('campaign', 'baseUri')}?parentSlug=$encodedSlug&limit=$limit",
            true
        );
        $response = $this->getHttpClient()->get($uri);

        $decoded = json_decode((string)$response->getBody(), true);
        Assertion::isArray($decoded);

        return $decoded;
    }
}
