<?php

declare(strict_types=1);

namespace MatchBot\Client;

use GuzzleHttp\Exception\RequestException;

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
            if ($exception->getResponse() && $exception->getResponse()->getStatusCode() === 404) {
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
}
