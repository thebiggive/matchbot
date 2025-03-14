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
        return [
            'additionalImageUris' => [0 => ['uri' => '', 'order' => 100,],],
            'aims' => [],
            'alternativeFundUse' => 'We have initiatives that require larger amounts of funding...',
            'amountRaised' => 50000.0,
            'bannerUri' => '',
            'beneficiaries' => [0 => 'General Public/Humankind', 1 => 'Women & Girls',],
            'budgetDetails' => [0 => ['description' => 'Budget item 1', 'amount' => 1000.0,],
                1 => ['description' => 'Budget item 2', 'amount' => 1000.0,],
                2 => ['description' => 'Budget item 3', 'amount' => 7500.0,],
                3 => ['description' => 'Overhead', 'amount' => 500.0,],
            ],
            'campaignCount' => null,
            'categories' => [0 => 'Health/Wellbeing', 1 => 'Medical Research', 2 => 'Mental Health',],
            'championName' => null,
            'championOptInStatement' => null,
            'championRef' => null,
            'charity' => ['website' => 'https://www.example.org/',
                'emailAddress' => 'email@example.com',
                'facebook' => 'https://www.facebook.com/xyz',
                'giftAidOnboardingStatus' => 'Invited to Onboard',
                'hmrcReferenceNumber' => null,
                'id' => '000000000000000000',
                'instagram' => 'https://www.linkedin.com/company/xyz',
                'linkedin' => 'https://www.linkedin.com/company/xyz/',
                'logoUri' => '',
                'name' => 'Some charity name',
                'optInStatement' => null,
                'phoneNumber' => null,
                'postalAddress' => ['postalCode' => 'W11 111', 'line2' => null, 'line1' => '12 Some Square', 'country' => 'United Kingdom', 'city' => 'London',],
                'regulatorNumber' => '1112345',
                'regulatorRegion' => 'England and Wales',
                'stripeAccountId' => 'acct_00000000',
                'twitter' => null,
            ],
            'countries' => [0 => 'United Kingdom',],
            'currencyCode' => 'GBP',
            'donationCount' => 100,
            'endDate' => '2025-12-31T23:59:00.000Z',
            'hidden' => false,
            'id' => 'a05xa000000',
            'impactReporting' => 'Impact will be measured according to the pillars..',
            'impactSummary' => 'Across our pillars, ',
            'isMatched' => true,
            'isRegularGiving' => false,
            'logoUri' => null,
            'matchFundsRemaining' => 50,
            'matchFundsTotal' => 5000.0,
            'parentAmountRaised' => null,
            'parentDonationCount' => null,
            'parentRef' => null,
            'parentTarget' => null,
            'parentUsesSharedFunds' => false,
            'problem' => 'The Foundation is addressing...',
            'quotes' => [0 => ['quote' => 'Dear Sam...',
                'person' => 'Alex',
            ],],
            'ready' => true,
            'regularGivingCollectionEnd' => null,
            'solution' => 'The Foundation is addressing...',
            'startDate' => '2024-10-09T08:00:00.000Z',
            'status' => 'Active',
            'summary' => 'We are raising funds...',
            'surplusDonationInfo' => null,
            'target' => 10000.0,
            'thankYouMessage' => 'Thank you for your support...',
            'title' => '2025 Year-End Matched-Funding Campaign',
            'updates' => [],
            'usesSharedFunds' => false,
            'video' =>
                ['provider' => 'youtube', 'key' => '12345',],
        ];
    }
}