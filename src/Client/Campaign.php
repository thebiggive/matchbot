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
     */
    public function getById(string $id): array
    {
        try {
            $response = $this->getHttpClient()->get("{$this->getSetting('campaign', 'baseUri')}/$id");
        } catch (RequestException $exception) {
            if ($exception->getResponse() && $exception->getResponse()->getStatusCode() === 404) {
                throw new NotFoundException('Campaign not found'); // may be safely caught in sandboxes
            }

            // Otherwise, an unknown error occurred -> re-throw
            throw $exception;
        }

        $campaign_as_json = json_decode($response->getBody()->getContents(), true);

        // Check the 'ready' field is set before we check if it's false.
        // Reason for this is if it's not set, we might still be interacting with an older
        // version of our SF API, and in that case we do not want to exclude campaigns, as
        // none of them will have the 'ready' field.
        if (isset($campaign_as_json['ready'])) {
            if (!$campaign_as_json['ready']) {
                throw new NotFoundException('Campaign not ready');
            }
        }

        return $campaign_as_json;
    }
}
