<?php

namespace MatchBot\Domain;

use Assert\InvalidArgumentException;
use Assert\LazyAssertionException;
use MatchBot\Application\Assertion;
use MatchBot\Application\Environment;
use MatchBot\Application\HttpModels\Campaign as CampaignHttpModel;
use MatchBot\Client\Campaign as CampaignClient;
use MatchBot\Domain\Campaign as CampaignDomainModel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * @psalm-import-type SFCampaignApiResponse from CampaignClient
 */
class CampaignService
{
    private const string CAMPAIGN_AMOUNT_RAISED_CACHE_PREFIX = 'campaign_amount_raised.';

    private const string CAMPAIGN_MATCH_AMOUNT_AVAILABLE_PREFIX = 'campaign_match_amount_available.';
    private const string CAMPAIGN_MATCH_AMOUNT_TOTAL_PREFIX = 'campaign_match_amount_total.';
    private const string METACAMPAIGN_MATCH_AMOUNT_TOTAL_PREFIX = 'metacampaign_match_amount_total';

    public function __construct(
        private CampaignRepository $campaignRepository,
        private MetaCampaignRepository $metaCampaignRepository,
        private CacheInterface $cache,
        private DonationRepository $donationRepository,
        private MatchFundsService $matchFundsRemainingService,
        private LoggerInterface $log,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function renderCampaignSummary(CampaignDomainModel $campaign): array
    {
        $sfCampaignData = $campaign->getSalesforceData();

        $campaignId = $campaign->getId();

        return [
            'charity' => [
                'id' => $campaign->getCharity()->getSalesforceId(),
                'name' => $campaign->getCharity()->getName(),
            ],
            'isRegularGiving' => $campaign->isRegularGiving(),
            'id' => $campaign->getSalesforceId(),
            'amountRaised' => $campaignId !== null ? $this->cachedAmountRaised($campaignId)->toMajorUnitFloat() : 0.0,
            'currencyCode' => $campaign->getCurrencyCode(),
            'endDate' => $this->formatDate($campaign->getEndDate()),
            'isMatched' => $campaign->isMatched(),
            'matchFundsRemaining' => $this->cachedMatchFundsRemaining($campaign)->toMajorUnitFloat(),
            'startDate' => $this->formatDate($campaign->getStartDate()),
            'status' => $campaign->getStatus(),
            'title' => $campaign->getCampaignName(),
            // fields below are all directly entered through the SF UI and not involved in business logic, so no need to
            // do anything more than this with them in matchbot for now.
            'beneficiaries' => $sfCampaignData['beneficiaries'],
            'categories' => $sfCampaignData['categories'],
            'championName' => $sfCampaignData['championName'],
            'imageUri' => $sfCampaignData['bannerUri'],
            'target' => $sfCampaignData['target'],

            // FE model also currently has a key-optional 'percentRaised' field, but SF never sends it and nothing in FE
            // runtime touches it - SF is currently doing its own division to calculate percent raised in
            // percentRaisedOfIndividualCampaign. We don't need to send it here but could start sending it in future.
        ];
    }

    /**
     * Converts an instance matchbot's internal campaign model to an HTTP model for use in front end.
     *
     * May need to use some additional data from outside the campaign at least if available. In the short term
     * we may need to call out to SF to get the most current data as required for some fields, e.g. amountRaised,
     * although if SF is not available or doesn't know this campaign we could fall back to a null or default value
     * perhaps.
     *
     * @return array<string, mixed> Campaign as associative array ready to render as JSON and send to FE
     */
    public function renderCampaign(CampaignDomainModel $campaign, ?MetaCampaign $metaCampaign): array
    {
        $charity = $campaign->getCharity();

        $campaignStatus = $campaign->getStatus();

        Assertion::inArray($campaignStatus, ['Active','Expired','Preview', null]);

        // the next two vars are the data as originally served to matchbot by Salesforce. For now we
        // repeat many parts of them verbatim, but its likely we may want to replace several fields with things
        // either computed inside matchbot or saved to specific fields in our own object model soon, so that we can
        // show more up-to-date results and also ensure that what we're presenting matches with data used for
        // matchbot business logic.

        $sfCampaignData = $campaign->getSalesforceData();
        $sfCharityData = $sfCampaignData['charity'];
        Assertion::notNull($sfCharityData, 'Charity data should not be null for a charity campaign');

        // The variables below currently being taken directly from the stored SF API response, but shouldn't be
        // because matchbot should be able to calculate more up to date or more authoritative versions of them
        // itself. We need to go through and implement a function to calculate each of these using our data
        // about the campaign and related fund(s), meta-campaign, etc.
        $parentMatchFundsRemaining = $sfCampaignData['parentMatchFundsRemaining'];
        $parentTarget = $sfCampaignData['parentTarget'];
        // end of variables to re-implement above. Other variables can continue being pulled directly from $sfCampaignData
        // as they are specific to the individual charity campaign and originate from user input in salesforce.

        try {
            $websiteUri = $charity->getWebsiteUri()?->__toString();
        } catch (\Laminas\Diactoros\Exception\InvalidArgumentException) {
            $this->log->warning("Bad website URI for charity {$charity->getSalesforceId()} for campaign {$campaign->getSalesforceId()}");
            $websiteUri = null;
        }

        $charityHttpModel = new \MatchBot\Application\HttpModels\Charity(
            id: $charity->getSalesforceId(),
            name: $charity->getName(),
            optInStatement: $sfCharityData['optInStatement'],
            facebook: $sfCharityData['facebook'],
            giftAidOnboardingStatus: $sfCharityData['giftAidOnboardingStatus'],
            hmrcReferenceNumber: $charity->getHmrcReferenceNumber(),
            instagram: $sfCharityData['instagram'],
            linkedin: $sfCharityData['linkedin'],
            twitter: $sfCharityData['twitter'],
            website: $websiteUri,
            phoneNumber: $charity->getPhoneNumber(),
            emailAddress: $charity->getEmailAddress()?->email,
            regulatorNumber: $charity->getRegulatorNumber(),
            regulatorRegion: $this->getRegionForRegulator($charity->getRegulator()),
            logoUri: $charity->getLogoUri()?->__toString(),
            stripeAccountId: $charity->getStripeAccountId(),
        );

        /** Non-null for any *launched* campaign; if it's null we know £0 has been raised. */
        $campaignId = $campaign->getId();

        if ($metaCampaign && $metaCampaign->usesSharedFunds()) {
            $parentDonationCount = $this->metaCampaignRepository->countCompleteDonationsToMetaCampaign($metaCampaign);
            $parentAmountRaised = $this->getAmountRaisedForMetaCampaign($metaCampaign)->toMajorUnitFloat();
        } else {
            $parentDonationCount = null;
            $parentAmountRaised = null;
        }


        $campaignHttpModel = new CampaignHttpModel(
            id: $campaign->getSalesforceId(),
            amountRaised: $campaignId === null ? 0 : $this->cachedAmountRaised($campaignId)->toMajorUnitFloat(),
            additionalImageUris: $sfCampaignData['additionalImageUris'],
            aims: $sfCampaignData['aims'],
            alternativeFundUse: $sfCampaignData['alternativeFundUse'],
            bannerUri: $sfCampaignData['bannerUri'],
            beneficiaries: $sfCampaignData['beneficiaries'],
            budgetDetails: $sfCampaignData['budgetDetails'],
            /* @mat-405-todo - remove this and any other properties that make sense only for meta-campaigns. Will require separating model in FE also */
            campaignCount: $sfCampaignData['campaignCount'],
            categories: $sfCampaignData['categories'],
            championName: $sfCampaignData['championName'],
            championOptInStatement: $sfCampaignData['championOptInStatement'],
            championRef: $sfCampaignData['championRef'],
            charity: $charityHttpModel,
            countries: $sfCampaignData['countries'],
            currencyCode: $campaign->getCurrencyCode() ?? '',
            donationCount: $this->donationRepository->countCompleteDonationsToCampaign($campaign),
            endDate: $this->formatDate($campaign->getEndDate()),
            hidden: $campaign->isHidden(),
            impactReporting: $sfCampaignData['impactReporting'],
            impactSummary: $sfCampaignData['impactSummary'],
            isMatched: $campaign->isMatched(),
            logoUri: $sfCampaignData['logoUri'],
            matchFundsRemaining: $this->cachedMatchFundsRemaining($campaign)->toMajorUnitFloat(),
            matchFundsTotal: $this->cachedTotalMatchFundsForCampaign($campaign)->toMajorUnitFloat(),
            parentAmountRaised: $parentAmountRaised,
            parentDonationCount: $parentDonationCount,
            parentMatchFundsRemaining: $parentMatchFundsRemaining,
            parentRef: $campaign->getMetaCampaignSlug()?->slug,
            parentTarget: $parentTarget,
            parentUsesSharedFunds: $metaCampaign && $metaCampaign->usesSharedFunds(),
            problem: $sfCampaignData['problem'],
            quotes: $sfCampaignData['quotes'],
            ready: $campaign->isReady(),
            solution: $sfCampaignData['solution'],
            startDate: $this->formatDate($campaign->getStartDate()),
            status: $campaignStatus,
            isRegularGiving: $campaign->isRegularGiving(),
            regularGivingCollectionEnd: $this->formatDate($campaign->getRegularGivingCollectionEnd()),
            summary: $sfCampaignData['summary'],
            surplusDonationInfo: $sfCampaignData['surplusDonationInfo'],
            target: $sfCampaignData['target'],
            thankYouMessage: $campaign->getThankYouMessage() ?? '',
            title: $campaign->getCampaignName(),
            updates: $sfCampaignData['updates'],
            usesSharedFunds: $sfCampaignData['usesSharedFunds'],
            video: $sfCampaignData['video'],
        );

        /** @var array<string, mixed> $campaignHttpModelArray */
        $campaignHttpModelArray = \json_decode(
            json: \json_encode($campaignHttpModel, \JSON_THROW_ON_ERROR),
            associative: true,
            depth: 512,
            flags: JSON_THROW_ON_ERROR
        );

        // In SF a few expired campaigns have null start and end date. Matchbot data model doesn't allow that
        // so using 1970 as placeholder for null, and then switching it back to null for display.
        // FE will display e.g. "Closed null" but that's existing behaviour that we don't need to fix right now.

        if (is_string($campaignHttpModelArray['startDate']) && \str_starts_with($campaignHttpModelArray['startDate'], '1970-01-01')) {
            $campaignHttpModelArray['startDate'] = null;
        }

        if (is_string($campaignHttpModelArray['endDate']) && \str_starts_with($campaignHttpModelArray['endDate'], '1970-01-01')) {
            $campaignHttpModelArray['endDate'] = null;
        }

        // We could just return $sfCampaignData to FE and not need to generate anything else with matchbot
        // logic, but that would keep FE indirectly coupled to the SF service. By making sure matchbot is able to
        // semi-independently regenerate the same thing we should be able to break the dependency and then later evolve
        // the mathbot<->frontend interface without needing to change SF.

        try {
            CampaignRenderCompatibilityChecker::checkCampaignHttpModelMatchesModelFromSF($campaignHttpModelArray, $sfCampaignData);
        } catch (LazyAssertionException $exception) {
            $errorMessages = \array_map(
                fn(InvalidArgumentException $e) => "{$e->getPropertyPath()}: {$e->getMessage()}",
                $exception->getErrorExceptions()
            );

            \ksort($errorMessages);

            $campaignHttpModelArray['errors'] = $errorMessages;
        }

        return $campaignHttpModelArray;
    }

    /**
     * Formats a date exactly as our SF API would, to allow easy checking for compatibility, returns e.g.
     * "2025-08-01T15:33:00.000Z"
     *
     * @psalm-param null|\DateTimeInterface $dateTime
     * @psalm-return ($dateTime is \DateTimeInterface ? string : null|string)
     */
    private function formatDate(?\DateTimeInterface $dateTime): ?string
    {
        if ($dateTime === null) {
            return null;
        }

        /** e.g. 2025-08-01T15:33:00Z */
        $formatted = $dateTime->format('Y-m-d\TH:i:sp');

        // I'm assuming the milliseconds part of all times served from our SF API is zero, since it seems to be
        // in tests and I can't imagine we ever need sub-second precision.
        return \str_replace('Z', '.000Z', $formatted);
    }

    /**
     * @psalm-param key-of<CampaignDomainModel::REGULATORS> |null $regulator
     * @phpstan-param 'CCEW'|'OSCR'|'CCNI'|null $regulator
     */
    private function getRegionForRegulator(?string $regulator): string
    {
        return match ($regulator) {
            'CCEW' => 'England and Wales',
            'OSCR' => 'Scotland',
            'CCNI' => 'Northern Ireland',
            null => 'Exempt', // have to assume the charity is exempt if not using any of the above regulators.
        };
    }

    /**
     * @param Salesforce18Id<Campaign> $sfId
     * @param SFCampaignApiResponse $campaignData
     * @return void
     *
     * Checks that the matchbot domain model and renderer would be able to correctly handle the given campaign
     * - i.e. that if and when SF goes down and we have to serve from the matchbot DB, or we switch the system
     * over to always serving from the matchbot DB the output will be compatible with this input.
     *
     * If the check fails in prod just logs an error, in other environments throws an exception which should not
     * be handled.
     */
    public function checkCampaignCanBeHandledByMatchbotDB(array $campaignData, Salesforce18Id $sfId): void
    {
        $mbDomainCharity = null;
        $mbDomainCampaign = null;

        try {
            /** Im-memory only matchbot domain model of charity and campaign, used just to check that our rendering matches
             * what SF would do.
             */
            $mbDomainCharity = $this->campaignRepository->newCharityFromCampaignData($campaignData);
            $mbDomainCampaign = Campaign::fromSfCampaignData(
                campaignData: $campaignData,
                salesforceId: $sfId,
                charity: $mbDomainCharity,
                fillInDefaultValues: true,
            );
            $campaignName = $mbDomainCampaign->getCampaignName();
            $campaignStatus = $mbDomainCampaign->getStatus() ?? 'NULL';

            $renderedCampaign = $this->renderCampaign($mbDomainCampaign, metaCampaign: null);

            /** @var list<string> $errors */
            $errors = $renderedCampaign['errors'] ?? [];
        } catch (\Throwable $t) {
            $campaignName = $campaignData['title'] ?? 'no-title';
            $campaignStatus = $campaignData['status'] ?? 'NULL';
            $errors = [$t->__toString()];
        }

        if (($errors) !== []) {
            $errorList = \implode(',', $errors);

            $errorMessage = "(MAT-405 NOT emergency) Campaign {$campaignName} {$sfId->value} status {$campaignStatus} not compatible: {$errorList}";

            if (Environment::current() === Environment::Production) {
                // @todo MAT-405: Fix the errors we've seen so far then change this from warning back to error
                $this->log->warning(
                    $errorMessage
                );
            } else {
                // logging error not rethrowing to make it easier to debug in staging for now.
                $this->log->error(
                    $errorMessage
                );
            }
        }

        // these models are only in memory, never persisted.
        Assertion::null($mbDomainCampaign?->getId());
        Assertion::null($mbDomainCharity?->getId());
    }

    /**
     * Gets the *cached* amount raised for a given charity campaign, based on donations in the Matchbot DB
     *
     * As this returns a cached value, do not rely on it for critical business logic.
     *
     * @return Money
     */
    public function cachedAmountRaised(int $campaignId): Money
    {
        // beta 1.0 below matches library default, controls probablistic early expiration to prevent stampedes. Likely to
        // be important especially important just after launching big meta campaigns. Stampedes for indivdual charity
        // campaigns will be controlled by the built-in symfony cache per key locking, so while one request is causing a
        // recompute of amount raised other requests will block if cache is expired.
        //
        // In very near future I may change this to caching a more complex object with multiple statistics about the
        // campaign, not just the amount raised.
        //
        // Values are for now only for public display, not for our own logic, so caching for 2 minutes is OK. Ideally
        // we would cache for longer when there are no donations, but we very much do not want to clear the cache
        // when we receive a new donation, since recalculating every time would be too expensive. We could consider
        // having a double layer cache with different expiration times, and clearing only one layer when a donation
        // is confirmed or refunded.

        $cachedAmountArray = $this->cache->get(
            key: self::CAMPAIGN_AMOUNT_RAISED_CACHE_PREFIX . $campaignId,
            callback: function (ItemInterface $item) use ($campaignId): array {
                $item->expiresAfter(120); // two minutes

                return $this->campaignRepository->totalAmountRaised($campaignId)->jsonSerialize();
            },
            beta: 1.0,
        );

        return Money::fromSerialized($cachedAmountArray);
    }

    private function cachedMatchFundsRemaining(Campaign $campaign): Money
    {
        $id = $campaign->getId();
        if ($id === null) {
            return Money::zero(Currency::GBP);
        }

        $cachedAmountArray = $this->cache->get(
            key: self::CAMPAIGN_MATCH_AMOUNT_AVAILABLE_PREFIX . (string)$id,
            callback: function (ItemInterface $item) use ($campaign): array {
                $item->expiresAfter(120); // two minutes
                $startTime = $this->clock->now();
                $returnValue = $this->matchFundsRemainingService->getFundsRemaining($campaign)->jsonSerialize();
                $endTime = $this->clock->now();

                $diffSeconds = $startTime->diff($endTime)->f;
                $this->log->info("Getting getFundsRemaining for campaign {$campaign->getSalesforceId()} took " . (string) $diffSeconds . "s");

                return $returnValue;
            }
        );

        return Money::fromSerialized($cachedAmountArray);
    }

    private function cachedTotalMatchFundsForCampaign(Campaign $campaign): Money
    {
        $id = $campaign->getId();
        if ($id === null) {
            return Money::zero(Currency::GBP);
        }

        $cachedAmountArray = $this->cache->get(
            key: self::CAMPAIGN_MATCH_AMOUNT_TOTAL_PREFIX . (string)$id,
            callback: function (ItemInterface $item) use ($campaign): array {
                $item->expiresAfter(120); // two minutes
                return $this->matchFundsRemainingService->getTotalFunds($campaign)->jsonSerialize();
            }
        );

        return Money::fromSerialized($cachedAmountArray);
    }

    public function getAmountRaisedForMetaCampaign(MetaCampaign $metaCampaign): Money
    {
        $id = $metaCampaign->getId();
        Assertion::notNull($id);

        $cachedAmountArray = $this->cache->get(
            key: self::METACAMPAIGN_MATCH_AMOUNT_TOTAL_PREFIX . (string)$id,
            callback: function (ItemInterface $item) use ($metaCampaign): array {
                $item->expiresAfter(120); // two minutes
                return $this->metaCampaignRepository->totalAmountRaised($metaCampaign)->jsonSerialize();
            }
        );

        return Money::fromSerialized($cachedAmountArray);
    }
}
