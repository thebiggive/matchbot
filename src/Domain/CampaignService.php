<?php

namespace MatchBot\Domain;

use Assert\AssertionFailedException;
use Assert\InvalidArgumentException;
use Assert\LazyAssertionException;
use MatchBot\Application\Assertion;
use MatchBot\Application\Environment;
use MatchBot\Application\HttpModels\Campaign as CampaignHttpModel;
use MatchBot\Client\Campaign as CampaignClient;
use MatchBot\Domain\Campaign as CampaignDomainModel;
use Psr\Log\LoggerInterface;

/**
 * @psalm-import-type SFCampaignApiResponse from CampaignClient
 */
class CampaignService
{
    /**
     * @psalm-suppress PossiblyUnusedMethod - used by DI,
     * will need to also be used in tests soon though.
     */
    public function __construct(
        private CampaignRepository $campaignRepository,
        private LoggerInterface $log
    ) {
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
    public function renderCampaign(CampaignDomainModel $campaign): array
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
            website: $charity->getWebsiteUri()?->__toString(),
            phoneNumber: $charity->getPhoneNumber(),
            emailAddress: $charity->getEmailAddress()?->email,
            regulatorNumber: $charity->getRegulatorNumber(),
            regulatorRegion: $this->getRegionForRegulator($charity->getRegulator()),
            logoUri: $charity->getLogoUri()?->__toString(),
            stripeAccountId: $charity->getStripeAccountId(),
        );

        $campaignHttpModel = new CampaignHttpModel(
            id: $campaign->getSalesforceId(),
            amountRaised: $sfCampaignData['amountRaised'],
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
            donationCount: $sfCampaignData['donationCount'],
            endDate: $this->formatDate($campaign->getEndDate()),
            hidden: $sfCampaignData['hidden'],
            impactReporting: $sfCampaignData['impactReporting'],
            impactSummary: $sfCampaignData['impactSummary'],
            isMatched: $campaign->isMatched(),
            logoUri: $sfCampaignData['logoUri'],
            matchFundsRemaining: $sfCampaignData['matchFundsRemaining'],
            matchFundsTotal: $sfCampaignData['matchFundsTotal'],
            parentAmountRaised: $sfCampaignData['parentAmountRaised'],
            parentDonationCount: $sfCampaignData['parentDonationCount'],
            parentMatchFundsRemaining: $sfCampaignData['parentMatchFundsRemaining'],
            parentRef: $sfCampaignData['parentRef'],
            parentTarget: $sfCampaignData['parentTarget'],
            parentUsesSharedFunds: $sfCampaignData['parentUsesSharedFunds'],
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

            $renderedCampaign = $this->renderCampaign($mbDomainCampaign);

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
                throw new \Exception($errorMessage);
            }
        }

        // these models are only in memory, never persisted.
        Assertion::null($mbDomainCampaign?->getId());
        Assertion::null($mbDomainCharity?->getId());
    }
}
