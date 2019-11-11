<?php

declare(strict_types=1);

namespace MatchBot\Client;

use GuzzleHttp\Exception\RequestException;
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
        try {
            $response = $this->getHttpClient()->post(
                $this->getSetting('donation', 'baseUri'),
                ['json' => $donation->toApiModel()]
            );
        } catch (RequestException $ex) {
            $this->logger->error('Donation create exception ' . get_class($ex) . ": {$ex->getMessage()}");
            throw new BadRequestException('Donation not created');
        }

        if ($response->getStatusCode() !== 200) {
            $this->logger->error('Donation create got non-success code ' . $response->getStatusCode());
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
        } catch (RequestException $ex) {
            $this->logger->error('Donation update exception ' . get_class($ex) . ": {$ex->getMessage()}");

            return false;
        }

        return ($response->getStatusCode() === 200);
    }

    private function hash(string $body): string
    {
        return hash_hmac('sha256', trim($body), getenv('WEBHOOK_DONATION_SECRET'));
    }
}
