<?php

declare(strict_types=1);

namespace MatchBot\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use MatchBot\Domain\Salesforce18Id;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

abstract class Common
{
    use HashTrait;

    /**
     * @var array{
     *     salesforce: array{ baseUri: string},
     *     global: array{timeout: string},
     * }
     */
    private readonly array $clientSettings;
    private ?Client $httpClient = null;
    protected readonly string $sfApiBaseUrl;

    /**
     * @param LoggerInterface $logger
     *
     * Suppress psalm issues in this function as Psalm seems to prefer to read the type of the param
     * rather than the type of the property, and its awkward to type the array based param.
     *
     * @psalm-suppress MixedAssignment
     * @psalm-suppress MixedArrayAccess
     */
    public function __construct(
        array $settings,
        protected LoggerInterface $logger
    ) {
        $this->clientSettings = $settings['apiClient'];
        $this->sfApiBaseUrl = $this->clientSettings['salesforce']['baseUri'];
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

    /**
     * @param 'donation'|'mandate' $entityType
     */
    protected function postUpdateToSalesforce(string $uri, array $jsonSnapshot, string $uuid, string $entityType): Salesforce18Id
    {
        if ((bool) getenv('DISABLE_CLIENT_PUSH')) {
            $this->logger->info("Client push off: Skipping upsert of $entityType {$uuid}}");
            throw new BadRequestException('Client push is off');
        }

        try {
            $response = $this->getHttpClient()->post(
                $uri,
                [
                    'json' => $jsonSnapshot,
                    'headers' => $this->getVerifyHeaders(json_encode($jsonSnapshot)),
                ]
            );
            $contents = $response->getBody()->getContents();
            $this->logIfNotProd($uri, $jsonSnapshot, $response, $contents);
        } catch (RequestException $ex) {
            $this->logger->info("Client PUsh RequestException: {$ex->getMessage()}");
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
            $exResponse = $ex->getResponse();
            if ($sandboxMissingLinkedResource) {
                /**
                 * The exception we throw here still takes the donation out of the push queue permenantly
                 * – {@see DonationRepository::doUpdate()} – but as this case is not as well
                 * understood, we also log a one time high severity error so we are better able to
                 * monitor these cases and try to understand what is happening in sandboxes that hit
                 * this edge case.
                 */
                $this->logger->error(sprintf(
                    '%s update skipped due to missing sandbox resource. Exception %s: %s. Body: %s',
                    $entityType,
                    get_class($ex),
                    $ex->getMessage(),
                    $exResponse ? $exResponse->getBody() : 'N/A',
                ));

                throw new NotFoundException();
            }

            $this->logger->error(sprintf(
                '%s upsert exception for UUID %s %s: %s. Body: %s',
                $entityType,
                $uuid,
                get_class($ex),
                $ex->getMessage(),
                $exResponse ? $exResponse->getBody() : 'N/A',
            ));

            throw new BadRequestException('not upserted');
        }

        if (!in_array($response->getStatusCode(), [200, 201], true)) {
            $this->logger->error("$entityType upsert got non-success code " . $response->getStatusCode());
            throw new BadRequestException("$entityType not upserted, response code " . $response->getStatusCode());
        }

        $this->logger->info("SF API response: $contents");

        try {
            /**
             * @var array{'salesforceId': string} $response
             */
            $response = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
            return Salesforce18Id::of($response['salesforceId']);
        } catch (\JsonException $e) {
            throw new \Exception(
                "JSON exception trying to parse response from SF '$contents'",
                $e->getCode(),
                $e
            );
        }
    }

    private function logIfNotProd(string $uri, array $jsonSnapshot, ResponseInterface $response, string $content): void
    {
        if (getenv('APP_ENV') === 'production') {
            return;
        }

        $requestBody = json_encode($jsonSnapshot);

        $this->logger->info(
            "Sent HTTP message. URI: `{$uri}`, " .
            "request body: $requestBody" .
            "response: `{$response->getStatusCode()} $content`"
        );
    }
}
