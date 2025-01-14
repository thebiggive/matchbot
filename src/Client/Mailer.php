<?php

namespace MatchBot\Client;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Originally created as a copy of the similar class BigGive\Identity\Client in Identity repo & adapted to fit in
 * Matchbot.
 */
class Mailer extends Common
{
    /**
     * @psalm-param array{templateKey: string, recipientEmailAddress: string, params: array, ...} $requestBody
     */
    public function sendEmail(array $requestBody): void
    {
        try {
            $uri = $this->baseUri() . '/v1/send';
            $response = $this->getHttpClient()->post(
                $uri,
                [
                    'json' => $requestBody,
                    'headers' => [
                        'x-send-verify-hash' => $this->hash(json_encode($requestBody)),
                    ],
                ]
            );

            if ($response->getStatusCode() === 200) {
                return;
            } else {
                $this->logger->warning(sprintf(
                    '%s email callout didn\'t return 200. It returned code: %s. Request body: %s. Response body: %s.',
                    $requestBody['templateKey'],
                    $response->getStatusCode(),
                    json_encode($requestBody),
                    $response->getBody()->getContents(),
                ));
                return;
            }
        } catch (RequestException $ex) {
            $response = $ex->getResponse();

            $this->logger->error(sprintf(
                '%s email exception %s with error code %s: %s. Body: %s',
                $requestBody['templateKey'],
                get_class($ex),
                $ex->getCode(),
                $ex->getMessage(),
                $response ? $response->getBody()->getContents() : 'N/A',
            ));
            return;
        } catch (GuzzleException $ex) {
            $this->logger->error(sprintf(
                '%s email exception %s with error code %s: %s. Body: %s',
                $requestBody['templateKey'],
                get_class($ex),
                $ex->getCode(),
                $ex->getMessage(),
                'N/A',
            ));
            return;
        }
    }

    private function hash(string $body): string
    {
        return hash_hmac('sha256', trim($body), $this->getSetting('mailer', 'sendSecret'));
    }

    protected function baseUri(): string
    {
        return $this->getSetting('mailer', 'baseUri');
    }
}
