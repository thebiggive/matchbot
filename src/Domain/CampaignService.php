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

        $charityHttpModel = new \MatchBot\Application\HttpModels\Charity(
            id: $charity->getSalesforceId(),
            name: $charity->getName(),
            optInStatement: '',
            facebook: '',
            giftAidOnboardingStatus: '',
            hmrcReferenceNumber: $charity->getHmrcReferenceNumber() ?? '',
            instagram: '',
            linkedin: '',
            twitter: '',
            website: '',
            phoneNumber: '',
            emailAddress: '',
            postalAddress: $charity->getPostalAddress()->toArray(),
            regulatorNumber: $charity->getRegulatorNumber(),
            regulatorRegion: $charity->getRegulator(),
            logoUri: $charity->getLogoUri()?->__toString(),
            stripeAccountId: $charity->getStripeAccountId(),
        );

        $campaignStatus = $campaign->getStatus();

        Assertion::inArray($campaignStatus, ['Active','Expired','Preview']);
        /** @var 'Active'|'Expired'|'Preview' $campaignStatus */

        $campaignHttpModel = new CampaignHttpModel(
            id: $campaign->getSalesforceId(),
            amountRaised: 0, // MAT-411-todo-before merge - replace this and similar placeholder values
            additionalImageUris: [],
            aims: [],
            alternativeFundUse: '',
            bannerUri: '',
            beneficiaries: [],
            budgetDetails: [],
            campaignCount: 0,
            categories: [],
            championName: '',
            championOptInStatement: '',
            championRef: null,
            charity: $charityHttpModel,
            countries: [],
            currencyCode: $campaign->getCurrencyCode() ?? '',
            donationCount: 0,
            endDate: \DateTimeImmutable::createFromInterface($campaign->getEndDate())->format(\DATE_ATOM),
            hidden: false,
            impactReporting: '',
            impactSummary: '',
            isMatched: $campaign->isMatched(),
            logoUri: '',
            matchFundsRemaining: 0,
            matchFundsTotal: 0,
            parentAmountRaised: 0,
            parentDonationCount: 0,
            parentMatchFundsRemaining: null,
            parentRef: '',
            parentTarget: 0,
            parentUsesSharedFunds: false,
            problem: '',
            quotes: [],
            ready: $campaign->isReady(),
            solution: '',
            startDate: $campaign->getStartDate()->format(\DATE_ATOM),
            status: $campaignStatus,
            isRegularGiving: $campaign->isRegularGiving(),
            regularGivingCollectionEnd: ($campaign->getRegularGivingCollectionEnd() ?? new \DateTimeImmutable('1970'))->format(\DATE_ATOM),
            summary: '',
            surplusDonationInfo: '',
            target: 0,
            thankYouMessage: $campaign->getThankYouMessage() ?? '',
            title: $campaign->getCampaignName(),
            updates: [],
            usesSharedFunds: false,
            video: null,
        );

        /** @var array<string, mixed> $campaignHttpModelArray */
        $campaignHttpModelArray = \json_decode(
            json: \json_encode($campaignHttpModel, \JSON_THROW_ON_ERROR),
            associative: true,
            depth: 512,
            flags: JSON_THROW_ON_ERROR
        );

        // We could just return $modelGeneratedFromSF to FE and not need to generate anything else with matchbot
        // logic, but that would keep FE indirectly coupled to the SF service. By making sure matchbot is able to
        // semi-independently regenerate the same thing we should be able to break the dependency and then later evolve
        // the mathbot<->frontend interface without needing to change SF.
        $modelGeneratedFromSF = $campaign->getSalesforceData() + ['charity' => $campaign->getCharity()->getSalesforceData()];

        try {
            CampaignRenderCompatibilityChecker::checkCampaignHttpModelMatchesModelFromSF($campaignHttpModelArray, $modelGeneratedFromSF);
        } catch (LazyAssertionException $exception) {
            $errorMessages = \array_map(
                fn(InvalidArgumentException $e) => ["{$e->getPropertyPath()}: {$e->getMessage()}"],
                $exception->getErrorExceptions()
            );

            \ksort($errorMessages);

            $campaignHttpModelArray['errors'] = $errorMessages;
        }

        return $campaignHttpModelArray;
    }
}
