<?php

namespace MatchBot\Client;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LogLevel;

/**
 * General client for the handful of endpoints where we simply give Donate JSON back verbatim.
 */
class GenericSalesforceGet extends Common
{
    /**
     * @param string $path without leading /
     * @throws GuzzleException on any request or response error.
     * @throws NotFoundException on non-200 response code.
     */
    public function get(string $path): StreamInterface
    {
        $uri = $this->getUri(uri: "{$this->sfApiBaseUrlCached}/$path", withCache: true);

        try {
            $response = $this->getHttpClient()->get($uri);

            if ($response->getStatusCode() === 200) {
                return $response->getBody();
            }

            $this->logger->warning(sprintf(
                'GenericSalesforceGet got HTTP code %s. Request URI: %s.',
                $response->getStatusCode(),
                $uri,
            ));

            throw new NotFoundException('Callout got non-200');
        } catch (GuzzleException $ex) {
            $level = $ex instanceof ClientException ? LogLevel::INFO : LogLevel::ERROR;
            $this->logger->log(
                $level,
                sprintf(
                    'GenericSalesforceGet exception %s with error code %s: %s. Request URI: %s',
                    get_class($ex),
                    $ex->getCode(),
                    $ex->getMessage(),
                    $uri,
                ),
            );

            throw $ex;
        }
    }
}
