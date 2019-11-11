<?php

declare(strict_types=1);

namespace MatchBot\Client;

use GuzzleHttp\Exception\ClientException;
use MatchBot\Domain\Donation as DonationModel;

class Donation extends Common
{
    /**
     * @param DonationModel $donation
     * @return string Salesforce donation ID
     * @throws BadRequestException
     */
    public function create(DonationModel $donation): string
    {
        $response = $this->getHttpClient()->post(
            $this->getSetting('donation', 'baseUri'),
            ['json' => $donation->toApiModel()]
        );

        if ($response->getStatusCode() !== 200) {
            throw new BadRequestException('Donation not created');
        }

        $donationCreatedResponse = json_decode($response->getBody()->getContents(), true);

        return $donationCreatedResponse['donation']['donationId'];
    }

    /**
     * For now, cancellations with Salesforce use the webhook receiver and not the Donations API
     * with JWT auth. As a server app there's no huge downside to using a fixed key, and getting
     * the Salesforce certificate's private part in the right format to use for RS256 JWT signature
     * creation was pretty involved. By sticking with this we can use the faster HS256 algorithm
     * for MatchBot's JWTs and not worry about compatibility with Salesforce's JWTs.
     *
     * @param DonationModel $donation
     * @return bool
     */
    public function put(DonationModel $donation): bool
    {
        try {
            $response = $this->getHttpClient()->put(
                $this->getSetting('webhook', 'baseUri') . "/donation/{$donation->getSalesforceId()}",
                [
                    'json' => $donation->toHookModel(),
                    'headers' => [
                        'X-Webhook-Verify-Hash' => $this->hash(json_encode($donation->toHookModel())),
                    ],
                ]
            );
        } catch (ClientException $exception) {
            $this->logger->error("Donation update failed: {$exception->getMessage()}");

            return false;
        }

        return ($response->getStatusCode() === 200);
    }

    private function hash(string $body): string
    {
        return hash_hmac('sha256', trim($body), getenv('WEBHOOK_DONATION_SECRET'));
    }
}
