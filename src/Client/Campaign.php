<?php

declare(strict_types=1);

namespace MatchBot\Client;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\TransferException;
use MatchBot\Application\Assertion;
use MatchBot\Domain\MetaCampaignSlug;

/**
 * // Some fields in the following type are marked optional because they do not yet exist in our prod SF org. They
 * // may also separately be nullable.
 *
 * @psalm-type SFCharityApiResponse = array{
     * id: string,
     * name: string,
     * logoUri: ?string,
     * phoneNumber: ?string,
     * emailAddress: ?string,
     * twitter: ?string,
     * website: ?string,
     * facebook: ?string,
     * linkedin: ?string,
     * instagram: ?string,
     * optInStatement: ?string,
     * stripeAccountId: string,
     * hmrcReferenceNumber: string|null,
     * giftAidOnboardingStatus: string,
     * regulatorRegion: string,
     * regulatorNumber: string|null,
 * }
 *
 * @psalm-type SFCampaignApiResponse = array{
 *     charity: SFCharityApiResponse|null,
 *     endDate: ?string,
 *     id: string,
 *     isMatched: bool,
 *     ready: bool,
 *     startDate: ?string,
 *     status: 'Active'|'Expired'|'Preview'|null,
 *     pinPosition?: int|null,
 *     championPagePinPosition?: int|null,
 *     relatedApplicationStatus?: value-of<\MatchBot\Domain\Campaign::APPLICATION_STATUSES>|null,
 *     relatedApplicationCharityResponseToOffer?: value-of<\MatchBot\Domain\Campaign::CHARITY_RESPONSES_TO_OFFER>|null,
 *     title: ?string,
*      currencyCode: string,
 *     isRegularGiving?: boolean,
 *     regularGivingCollectionEnd?: ?string,
 *     thankYouMessage: ?string,
 *     aims: list<string>,
 *     target: ?float,
 *     problem: ?string,
 *     solution: ?string,
 *     bannerUri: ?string,
 *     banner?: array{uri: string, alt_text: ?string}|null,
 *     amountRaised: float,
 *     summary: string,
 *     countries: list<string>,
 *     categories: list<string>,
 *     budgetDetails: list<array{amount: float, description: string}>,
 *     beneficiaries: list<string>,
 *     matchFundsTotal: float,
 *     matchFundsRemaining: float,
 *     video: array{key: string, provider: string}|null,
 *     usesSharedFunds: bool,
 *     updates: list<array{content: string, modifiedDate: string}>,
 *     surplusDonationInfo: string,
 *     quotes: list<array{person: string, quote: string}>,
 *     parentUsesSharedFunds: boolean,
 *     parentTarget: ?float,
 *     parentRef: ?string,
 *     parentMatchFundsRemaining: ?float,
 *     parentDonationCount: ?int,
 *     parentAmountRaised: ?float,
 *     totalAdjustment: ?float,
 *     logoUri: ?string,
 *     impactSummary: ?string,
 *     impactReporting: ?string,
 *     hidden: bool,
 *     donationCount: ?int,
 *     championRef: ?string,
 *     championOptInStatement: string,
 *     championName: string,
 *     campaignCount: ?int,
 *     alternativeFundUse: ?string,
 *     additionalImageUris: list<array{order: int, uri: string}>,
 *     isMetaCampaign: ?bool,
 *     isEmergencyIMF: bool,
 *     slug: ?string,
 *     campaignFamily: string,
 *     imfCampaignTargetOverride?: float,
 *     totalFundingAllocation?: float,
 *     amountPledged?: float,
 *     totalMatchedFundsAvailable?: float,
 *     totalFundraisingTarget?: float,
 *     masterCampaignStatus?: string,
 *     campaignStatus?: string,
 *     }
 */

class Campaign extends Common
{
    /**
     * @param string $id
     * @return SFCampaignApiResponse Single Campaign response object as associative array
     * @throws NotFoundException if Campaign with given ID not found
     * @throws TransferException if connection or request fails twice
     */
    public function getById(string $id, bool $withCache): array
    {
        $baseUri = $withCache ? $this->baseUriCached() : $this->baseUri();
        $uri = $this->getUri("$baseUri/$id", $withCache);

        $maxRetries = 1;
        for ($retries = 0; $retries <= $maxRetries; $retries++) {
            try {
                $response = $this->getHttpClient()->get($uri, ['timeout' => 15]);

                /**
                 * @var SFCampaignApiResponse $campaignResponse
                 */
                $campaignResponse = json_decode((string)$response->getBody(), true, flags: \JSON_THROW_ON_ERROR);

                return $campaignResponse;
            } catch (TransferException $exception) {
                if ($exception instanceof RequestException && $exception->getResponse()?->getStatusCode() === 404) {
                    // may be safely caught in sandboxes
                    throw new NotFoundException(sprintf('Campaign ID %s not found in SF', $id));
                }

                $logMessage = sprintf(
                    'Campaign get for ID %s failed with %s: %s',
                    $id,
                    get_class($exception),
                    $exception->getMessage()
                );

                if ($retries < $maxRetries) {
                    $this->logger->info($logMessage . ". Retrying...");
                    continue;
                }

                $this->logger->warning($logMessage . ". Giving up after {$maxRetries} retries.");

                // Otherwise, an unknown error occurred and no retries -> re-throw
                throw $exception;
            }
        }

        throw new \LogicException('Should either have returned a campaign or thrown an exception.');
    }

    /**
     * @return SFCampaignApiResponse Single Campaign response object as associative array
     * @throws NotFoundException
     */
    public function getBySlug(MetaCampaignSlug $slug): array
    {
        $uri = $this->getUri("{$this->baseUriCached()}/slug/$slug->slug", true);
        try {
            $response = $this->getHttpClient()->get($uri);
        } catch (RequestException $exception) {
            if ($exception->getResponse()?->getStatusCode() === 404) {
                // may be safely caught in sandboxes
                throw new NotFoundException(sprintf('Campaign slug %s not found in SF', $slug->slug));
            }

            // Otherwise, an unknown error occurred -> re-throw
            throw $exception;
        }

        /**
         * @var SFCampaignApiResponse $campaignResponse
         */
        $campaignResponse = json_decode((string)$response->getBody(), true, flags: \JSON_THROW_ON_ERROR);

        return $campaignResponse;
    }

    /**
     * Returns a list of all campaigns associated with the meta-campagin with the given slug.
     *
     * @psalm-suppress MoreSpecificReturnType
     * @psalm-suppress LessSpecificReturnStatement
     * @return list<array>
     */
    public function findCampaignsForMetaCampaign(MetaCampaignSlug $metaCampaignSlug, int $limit = 100): array
    {
        $campaigns = [];
        $encodedSlug = urlencode($metaCampaignSlug->slug);

        $offset = 0;
        $pageSize = 100;
        $foundEmptyPage = false;
        while ($offset < $limit) {
            $uri = $this->getUri(
                "{$this->baseUriCached()}?parentSlug=$encodedSlug&limit=$pageSize&offset=$offset",
                true
            );
            $response = $this->getHttpClient()->get($uri);

            $decoded = json_decode((string)$response->getBody(), true);

            Assertion::isArray($decoded);
            if ($decoded === []) {
                $foundEmptyPage = true;
                break;
            }

            $campaigns = [...$campaigns, ...$decoded];
            $offset += $pageSize;
        }

        if (! $foundEmptyPage) {
            throw new \Exception(
                "Did not find empty page in campaign search results, too many campaigns in metacampaign?"
            );
        }

        return $campaigns;
    }

    private function baseUri(): string
    {
        return $this->sfApiBaseUrl . '/campaigns/services/apexrest/v1.0/campaigns';
    }

    private function baseUriCached(): string
    {
        return $this->sfApiBaseUrlCached . '/campaigns/services/apexrest/v1.0/campaigns';
    }
}
