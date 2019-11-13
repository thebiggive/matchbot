<?php

declare(strict_types=1);

namespace MatchBot\Client;

use GuzzleHttp\Client;
use Psr\Log\LoggerInterface;

abstract class Common
{
    /** @var array */
    private $clientSettings;

    /** @var Client */
    private $httpClient;
    /** @var LoggerInterface */
    protected $logger;

    public function __construct(array $settings, LoggerInterface $logger)
    {
        $this->clientSettings = $settings['apiClient'];
        $this->logger = $logger;
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
