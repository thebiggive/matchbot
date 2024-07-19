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
    public function createOrUpdate(DonationModel $donation): string
    {
        if (getenv('DISABLE_CLIENT_PUSH')) {
            $this->logger->info("Client push off: Skipping create of donation {$donation->getUuid()}");
            throw new BadRequestException('Client push is off');
        }

        try {
            $response = $this->getHttpClient()->post(
                $this->getSetting('donation', 'baseUri') . '/' . $donation->getUuid(),
                ['json' => $donation->toApiModel(forSalesforce: true)]
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

            $this->logger->error(sprintf(
                'Donation upsert exception for donation UUID %s %s: %s. Body: %s',
                $donation->getUuid(),
                get_class($ex),
                $ex->getMessage(),
                $ex->getResponse() ? $ex->getResponse()->getBody() : 'N/A',
            ));

            throw new BadRequestException('Donation not created');
        }

        if (! in_array($response->getStatusCode(), [200, 201], true)) {

            $this->logger->error('Donation upsert got non-success code ' . $response->getStatusCode());
            throw new BadRequestException('Donation not upserted');
        }

        /**
         * @var array{'salesforceId': string} $donationCreatedResponse
         */
        $donationCreatedResponse = json_decode((string) $response->getBody(), true);

        // todo add new property that SF now returns to API docs as distinct from donationId.
        // Semantics were unclear before and SF was sometimes putting its own IDs in `donationId` I think.

        return $donationCreatedResponse['salesforceId'];
    }
}
