<?php

namespace MatchBot\Client;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use MatchBot\Application\Email\EmailMessage;
use MatchBot\Application\Environment;

/**
 * Originally created as a copy of the similar class BigGive\Identity\Client in Identity repo & adapted to fit in
 * Matchbot.
 */
class Mailer extends Common
{
    /**
     * @deprecated for public use - use {@see self::send()} instead.
     *
     * @param array{templateKey: string, recipientEmailAddress: string, params: array<string, mixed>, ...} $requestBody
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
                        'x-send-verify-hash' => $this->hash(json_encode($requestBody, \JSON_THROW_ON_ERROR)),
                        'User-Agent' => 'matchbot',
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
                    json_encode($requestBody, \JSON_THROW_ON_ERROR),
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

            if (!Environment::current()->isProduction()) {
                $this->logger->error("Message request data: " . json_encode($requestBody, \JSON_THROW_ON_ERROR));
            }

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
        return hash_hmac('sha256', trim($body), $this->getMailerSetting('sendSecret'));
    }

    private function baseUri(): string
    {
        return $this->getMailerSetting('baseUri');
    }

    public function send(EmailMessage $command): void
    {
        /** @psalm-suppress DeprecatedMethod */
        $this->sendEmail(
            [
                'templateKey' => $command->templateKey,
                'recipientEmailAddress' => $command->emailAddress->email,
                'params' => $command->params,
            ]
        );
    }
}
