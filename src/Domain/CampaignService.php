<?php

namespace MatchBot\Domain;

use Assert\InvalidArgumentException;
use Assert\LazyAssertionException;
use MatchBot\Application\Assertion;
use MatchBot\Application\HttpModels\Campaign as CampaignHttpModel;
use MatchBot\Domain\Campaign as CampaignDomainModel;

class CampaignService
{
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

        Assertion::inArray($campaignStatus, ['Active','Expired','Preview']);
        /** @var 'Active'|'Expired'|'Preview' $campaignStatus */

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
            postalAddress: $charity->getPostalAddress()->toArray(),
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
}
