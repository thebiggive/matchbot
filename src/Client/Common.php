<?php

declare(strict_types=1);

namespace MatchBot\Client;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

abstract class Common
{
    private array $clientSettings;
    private Client $httpClient;

    public function __construct(
        array $settings,
        protected LoggerInterface $logger
    ) {
        $this->clientSettings = $settings['apiClient'];
    }

    protected function getSetting(string $service, string $property): string
    {
        return $this->clientSettings[$service][$property];
    }

    protected function getHttpClient(): Client
    {
        if (!isset($this->httpClient)) {
            $this->httpClient = new Client([
                'timeout' => $this->clientSettings['global']['timeout'],
            ]);
        }

        return $this->httpClient;
    }
}
