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
                throw new NotFoundException(sprintf('Campaign ID %s not found', $id)); // may be safely caught in sandboxes
            }

            // Otherwise, an unknown error occurred -> re-throw
            throw $exception;
        }

        $campaignResponse = json_decode((string) $response->getBody(), true);

        if (!$campaignResponse['ready']) {
            throw new NotFoundException(sprintf('Campaign ID %s not ready', $id));
        }

        return $campaignResponse;
    }
}
