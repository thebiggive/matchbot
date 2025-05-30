<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignRenderCompatibilityChecker;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundType;
use MatchBot\Tests\TestCase;

class CampaignRenderCompatibilityCheckerTest extends TestCase
{
    private const array CAMPAIGN_FROM_SALESOFRCE = [
        'id' => 'a05xxxxxxxxxxxxxxx',
        'aims' => [0 => 'First Aim'],
        'ready' => true,
        'title' => 'Save Matchbot',
        'video' => null,
        'hidden' => false,
        'quotes' => [],
        'status' => 'Active',
        'target' => 100.0,
        'endDate' => '2095-08-01T00:00:00.000Z',
        'logoUri' => null,
        'problem' => 'Matchbot is threatened!',
        'summary' => 'We can save matchbot',
        'updates' => [],
        'solution' => 'do the saving',
        'bannerUri' => null,
        'countries' => [0 => 'United Kingdom',],
        'isMatched' => true,
        'parentRef' => null,
        'startDate' => '2015-08-01T00:00:00.000Z',
        'categories' => ['Education/Training/Employment', 'Religious'],
        'championRef' => null,
        'amountRaised' => 0.0,
        'championName' => null,
        'currencyCode' => 'GBP',
        'parentTarget' => null,
        'beneficiaries' => ['Animals'],
        'budgetDetails' => [
            ['amount' => 23.0, 'description' => 'Improve the code'],
            ['amount' => 27.0, 'description' => 'Invent a new programing paradigm'],
        ],
        'campaignCount' => null,
        'donationCount' => 0,
        'impactSummary' => null,
        'impactReporting' => null,
        'isRegularGiving' => false,
        'matchFundsTotal' => 50.0,
        'thankYouMessage' => 'Thank you for helping us save matchbot! We will be able to match twice as many bots now!',
        'usesSharedFunds' => false,
        'alternativeFundUse' => null,
        'parentAmountRaised' => null,
        'additionalImageUris' => [],
        'matchFundsRemaining' => 50.0,
        'parentDonationCount' => null,
        'surplusDonationInfo' => null,
        'parentUsesSharedFunds' => false,
        'championOptInStatement' => null,
        'parentMatchFundsRemaining' => null,
        'regularGivingCollectionEnd' => null,
        'charity' => [
            'id' => 'xxxxxxxxxxxxxxxxxx',
            'name' => 'Society for the advancement of bots and matches',
            'logoUri' =>  null,
            'twitter' => null,
            'website' => 'https://society-for-the-advancement-of-bots-and-matches.localhost',
            'facebook' => 'https://www.facebook.com/botsAndMatches',
            'linkedin' => 'https://www.linkedin.com/company/botsAndMatches',
            'instagram' => 'https://www.instagram.com/botsAndMatches',
            'phoneNumber' => null,
            'emailAddress' => 'bots-and-matches@example.com',
            'optInStatement' => null,
            'regulatorNumber' => '1000000',
            'regulatorRegion' => 'England and Wales',
            'stripeAccountId' => 'acc_123456',
            'hmrcReferenceNumber' => null,
            'giftAidOnboardingStatus' => 'Invited to Onboard',
        ]
    ];

    /**
     * No assertions as we just check our SUT does not throw exception.
     *
     * @doesNotPerformAssertions
     */
    public function testMatchbotAmountRaisedIsCompatibleWithSalesforce(): void
    {
        // arrange
        $actual = self::CAMPAIGN_FROM_SALESOFRCE;

        $actual['amountRaised'] += 1.0; // simulates a donation in MB that hasn't been rolled up in SF yet.

        // act
        CampaignRenderCompatibilityChecker::checkCampaignHttpModelMatchesModelFromSF($actual, self::CAMPAIGN_FROM_SALESOFRCE);
    }

    public function testWrongTargetNotCompatibleWithSalesforce(): void
    {
        // arrange
        $actual = self::CAMPAIGN_FROM_SALESOFRCE;
        $actual['target'] += 1.0; // simulates something gone badly wrong

        // assert
        $this->expectExceptionMessage('target: Value "101" does not equal expected value "100".');

        // act
        CampaignRenderCompatibilityChecker::checkCampaignHttpModelMatchesModelFromSF($actual, self::CAMPAIGN_FROM_SALESOFRCE);
    }
}
