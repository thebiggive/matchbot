<?php

declare(strict_types=1);

namespace MatchBot\Client;

use GuzzleHttp\Client;

abstract class Common
{
    /** @var array */
    private $clientSettings;

    /** @var Client */
    private $httpClient;

    public function __construct(array $settings)
    {
        $this->clientSettings = $settings['apiClient'];
    }

    protected function getSetting(string $service, string $property): string
    {
        return $this->clientSettings[$service][$property];
    }

    protected function getHttpClient(): Client
    {
        if (!$this->httpClient) {
            $this->httpClient = new Client();
        }

        return $this->httpClient;
    }
}
