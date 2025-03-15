<?php

namespace MatchBot\Application;

use MatchBot\Domain\Campaign;

/**
 * Renders a campaign to a JSON-like array for FE. Initially with all dummy data, but then some of that dummy
 * data will be replaced with real data from the campaign object.
 */
class DummyCampaignRenderer {

    /**
     * @return array<string, mixed>
     */
    static function renderCampaign(Campaign $campaign): array
    {
        $charity = $campaign->getCharity();

        return [
            'additionalImageUris' => $campaign->getRawData()['additionalImageUris'],
            'aims' => $campaign->getRawData()['aims'],
            'alternativeFundUse' => $campaign->getRawData()['alternativeFundUse'],
            'amountRaised' => $campaign->getRawData()['amountRaised'],
            'bannerUri' => $campaign->getRawData()['bannerUri'],
            'beneficiaries' => $campaign->getRawData()['beneficiaries'],
            'budgetDetails' => $campaign->getRawData()['budgetDetails'],
            'campaignCount' => $campaign->getRawData()['campaignCount'],
            'categories' => $campaign->getRawData()['categories'],
            'championName' => $campaign->getRawData()['championName'],
            'championOptInStatement' => $campaign->getRawData()['championOptInStatement'],
            'championRef' => $campaign->getRawData()['championRef'],
            'charity' =>
                [
                    'website' => $charity->getWebsiteUri()?->__toString(),
//                'emailAddress' => $charity->getEmailAddress(), // not used by FE
                'facebook' =>  $charity->getRawData()['facebook'],
                'giftAidOnboardingStatus' => $charity->getRawData()['giftAidOnboardingStatus'],
                'hmrcReferenceNumber' => $charity->getRawData()['hmrcReferenceNumber'],
                'id' => $charity->getSalesforceId(),
                'instagram' => $charity->getRawData()['instagram'],
                'linkedin' => $charity->getRawData()['linkedin'],
                'logoUri' => $charity->getLogoUri()?->__toString(),
                'name' => $charity->getName(),
                'optInStatement' => $charity->getRawData()['optInStatement'],
                'phoneNumber' => $charity->getPhoneNumber(),
//                'postalAddress' => $charity->getPostalAddress(), // not used by FE
                'regulatorNumber' => $charity->getRegulatorNumber(),
                'regulatorRegion' => $charity->getRawData()['regulatorRegion'],
                'stripeAccountId' => $charity->getStripeAccountId(),
                'twitter' => $charity->getRawData()['twitter'],
            ],
            'countries' => $campaign->getRawData()['countries'],
            'currencyCode' => $campaign->getCurrencyCode(),
            'donationCount' => $campaign->getRawData()['donationCount'],
            'endDate' => $campaign->getEndDate()->format('c'),
            'hidden' => $campaign->getRawData()['hidden'],
            'id' => $campaign->getSalesforceId(),
            'impactReporting' => $campaign->getRawData()['impactReporting'],
            'impactSummary' => $campaign->getRawData()['impactSummary'],
            'isMatched' => $campaign->isMatched(),
            'isRegularGiving' => $campaign->isRegularGiving(),
            'logoUri' => $campaign->getRawData()['logoUri'],
            'matchFundsRemaining' => $campaign->getRawData()['matchFundsRemaining'],
            'matchFundsTotal' => $campaign->getRawData()['matchFundsTotal'],
            'parentAmountRaised' => $campaign->getRawData()['parentAmountRaised'],
            'parentDonationCount' => $campaign->getRawData()['parentDonationCount'],
            'parentRef' => $campaign->getRawData()['parentRef'],
            'parentTarget' => $campaign->getRawData()['parentTarget'],
            'parentUsesSharedFunds' => $campaign->getRawData()['parentUsesSharedFunds'],
            'problem' =>  $campaign->getRawData()['problem'],
            'quotes' => $campaign->getRawData()['quotes'],
            'ready' => $campaign->isReady(),
            'regularGivingCollectionEnd' => $campaign->getRegularGivingCollectionEnd()?->format('c'),
            'solution' => $campaign->getRawData()['solution'],
            'startDate' => $campaign->getRawData()['startDate'],
            'status' => $campaign->getRawData()['status'],
            'summary' => $campaign->getRawData()['summary'],
            'surplusDonationInfo' => $campaign->getRawData()['surplusDonationInfo'],
            'target' => $campaign->getRawData()['target'],
            'thankYouMessage' => $campaign->getThankYouMessage(),
            'title' => $campaign->getCampaignName(),
            'updates' => $campaign->getRawData()['updates'],
            'usesSharedFunds' => $campaign->getRawData()['usesSharedFunds'],
            'video' => $campaign->getRawData()['video'],
        ];
    }
}