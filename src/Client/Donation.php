<?php

declare(strict_types=1);

namespace MatchBot\Client;

use GuzzleHttp\Exception\RequestException;
use MatchBot\Domain\Donation as DonationModel;
use MatchBot\Domain\DonationRepository;

class Donation extends Common
{
    /**
     * @param DonationModel $donation
     * @return string Salesforce donation ID
     * @throws BadRequestException
     */
    public function create(DonationModel $donation): string
    {
        if (getenv('DISABLE_CLIENT_PUSH')) {
            $this->logger->info("Client push off: Skipping create of donation {$donation->getUuid()}");
            throw new BadRequestException('Client push is off');
        }

        try {
            $response = $this->getHttpClient()->post(
                $this->getSetting('donation', 'baseUri'),
                ['json' => $donation->toApiModel()]
            );
        } catch (RequestException $ex) {
            // Sandboxes that 404 on POST may be trying to sync up donations for non-existent campaigns and
            // so have probably just been refreshed. In this case we want to update the local state of play
            // to stop them getting pushed, instead of treating this as an error. So throw this for appropriate
            // handling in the caller without an error level log. In production, 404s should not happen and
            // so we continue to throw a `BadRequestException` which means `DonationRepostitory::doCreate()`
            // will return false and the caller will log an error.
            if ($ex->getCode() === 404 && getenv('APP_ENV') !== 'production') {
                throw new NotFoundException();
            }

            $this->logger->error(sprintf(
                'Donation create exception %s: %s. Body: %s',
                get_class($ex),
                $ex->getMessage(),
                $ex->getResponse() ? $ex->getResponse()->getBody() : 'N/A',
            ));

            throw new BadRequestException('Donation not created');
        }

        if ($response->getStatusCode() !== 200) {
            $this->logger->error('Donation create got non-success code ' . $response->getStatusCode());
            throw new BadRequestException('Donation not created');
        }

        $donationCreatedResponse = json_decode((string) $response->getBody(), true);

        return $donationCreatedResponse['donation']['donationId'];
    }

    /**
     * For now, updates with Salesforce use the webhook receiver and not the Donations API
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
        if (getenv('DISABLE_CLIENT_PUSH')) {
            $this->logger->info("Client push off: Skipping update of donation {$donation->getUuid()}");

            return false;
        }

        try {
            $requestBody = $donation->toHookModel();
            $response = $this->getHttpClient()->put(
                $this->getSetting('webhook', 'baseUri') . "/donation/{$donation->getSalesforceId()}",
                [
                    'json' => $requestBody,
                    'headers' => [
                        'X-Webhook-Verify-Hash' => $this->hash(json_encode($requestBody)),
                    ],
                ]
            );
        } catch (RequestException $ex) {
            // Sandboxes that 404 on PUT have probably just been refreshed. In this case we want to
            // update the local state of play to stop them getting pushed, instead of treating this
            // as an error. So throw this for appropriate handling in the caller without an error level
            // log. In production, 404s should not happen and so we continue to `return false` which
            // will lead the caller to log an error.
            if ($ex->getCode() === 404 && getenv('APP_ENV') !== 'production') {
                throw new NotFoundException();
            }

            $sandboxMissingLinkedResource = (
                $ex->getCode() === 400 &&
                getenv('APP_ENV') !== 'production' &&
                str_contains($ex->getMessage(), '"entity is deleted"')
            );
            if ($sandboxMissingLinkedResource) {
                /**
                 * The exception we throw here still takes the donation out of the push queue permenantly
                 * – {@see DonationRepository::doUpdate()} – but as this case is not as well
                 * understood, we also log a one time high severity error so we are better able to
                 * monitor these cases and try to understand what is happening in sandboxes that hit
                 * this edge case.
                 */
                $this->logger->error(sprintf(
                    'Donation update skipped due to missing sandbox resource. Exception %s: %s. Body: %s',
                    get_class($ex),
                    $ex->getMessage(),
                    $ex->getResponse() ? $ex->getResponse()->getBody() : 'N/A',
                ));

                throw new NotFoundException();
            }

            // All other errors should be logged so we get a notification and the app left to retry the
            // push at a later date.
            $this->logger->warning(sprintf(
                'Donation update exception %s: %s. Body: %s',
                get_class($ex),
                $ex->getMessage(),
                $ex->getResponse() ? $ex->getResponse()->getBody() : 'N/A',
            ));

            return false;
        }

        return ($response->getStatusCode() === 200);
    }

    private function hash(string $body): string
    {
        return hash_hmac('sha256', trim($body), getenv('WEBHOOK_DONATION_SECRET'));
    }
}
