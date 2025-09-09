<?php

declare(strict_types=1);

namespace MatchBot\Client;

use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use MatchBot\Application\Settings;

/**
 * Client to handle mailing list signups via Salesforce
 */
class MailingList extends Common
{
    use HashTrait;

    public function __construct(
        Settings $settings,
        LoggerInterface $logger
    ) {
        parent::__construct($settings, $logger);
    }

    /**
     * Send a mailing list signup request to Salesforce
     *
     * @param string $mailingList Either 'donor' or 'charity'
     * @param string $firstName First name of the person signing up
     * @param string $lastName Last name of the person signing up
     * @param string $emailAddress Email address of the person signing up
     * @param string|null $jobTitle Job title (required for charity mailing list)
     * @param string|null $organisationName Organisation name
     * @return bool Whether the signup was successful
     * @throws BadRequestException
     * @throws BadResponseException
     * @throws GuzzleException
     */
    public function signup(
        string $mailingList,
        string $firstName,
        string $lastName,
        string $emailAddress,
        ?string $jobTitle = null,
        ?string $organisationName = null
    ): bool {
        $uri = $this->sfApiBaseUrl . '/mailing-list-signup';

        $payload = [
            'mailingList' => $mailingList,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'emailAddress' => $emailAddress,
        ];

        if ($jobTitle !== null) {
            $payload['jobTitle'] = $jobTitle;
        }

        if ($organisationName !== null) {
            $payload['organisationName'] = $organisationName;
        }

        try {
            $response = $this->getHttpClient()->post(
                $uri,
                [
                    'json' => $payload,
                    'headers' => $this->getVerifyHeaders(json_encode($payload, \JSON_THROW_ON_ERROR)),
                ]
            );

            return $response->getStatusCode() === 200 || $response->getStatusCode() === 201;
        } catch (GuzzleException $ex) {
            $this->logger->error(sprintf(
                'Mailing list signup exception: %s: %s',
                get_class($ex),
                $ex->getMessage()
            ));

            throw $ex;
        }
    }
}
