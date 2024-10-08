<?php

declare(strict_types=1);

namespace MatchBot\Client;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

abstract class Common
{
    private array $clientSettings;
    private ?Client $httpClient = null;

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
        if ($this->httpClient === null) {
            $this->httpClient = new Client([
                'timeout' => $this->clientSettings['global']['timeout'],
            ]);
        }

        return $this->httpClient;
    }

    protected function getUri(string $uri, bool $withCache): string
    {
        if (!$withCache) {
            $uri .= '?nocache=1';
        }

        return $uri;
    }
}
