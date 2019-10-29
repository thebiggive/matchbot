<?php

declare(strict_types=1);

namespace MatchBot\Client;

class Campaign extends Common
{
    /**
     * @param string $id
     * @return array Single Campaign response object as associative array
     * @throws NotFoundException if Campaign with given ID not found
     */
    public function getById(string $id): array
    {
        $response = $this->getHttpClient()->get("{$this->getSetting('campaign', 'baseUri')}/$id");

        if ($response->getStatusCode() !== 200) {
            throw new NotFoundException('Campaign not found');
        }

        return json_decode($response->getBody()->getContents(), true);
    }
}
