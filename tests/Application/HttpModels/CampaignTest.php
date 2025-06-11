<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\HttpModels;

use MatchBot\Application\HttpModels\Campaign;
use MatchBot\Application\HttpModels\Charity;
use MatchBot\Tests\TestCase;

/**
 * Tests for the Campaign HTTP model class
 */
class CampaignTest extends TestCase
{
    /**
     * Test that the Campaign class can be instantiated with valid data
     */
    public function testItInstantiatesWithValidData(): void
    {
        $charity = new Charity(
            id: 'charityId123',
            name: 'Test Charity',
            optInStatement: 'Opt-in statement',
            facebook: null,
            giftAidOnboardingStatus: null,
            hmrcReferenceNumber: null,
            instagram: null,
            linkedin: null,
            twitter: null,
            website: null,
            phoneNumber: null,
            emailAddress: null,
            regulatorNumber: null,
            regulatorRegion: null,
            logoUri: null,
            stripeAccountId: null,
        );

        // Create a campaign with all properties set
        $campaign = new Campaign(
            id: 'campaignId123',
            amountRaised: 1000.50,
            additionalImageUris: [['uri' => 'https://example.com/image1.jpg', 'order' => 1]],
            aims: ['Aim 1', 'Aim 2'],
            alternativeFundUse: 'Alternative fund use',
            bannerUri: 'https://example.com/banner.jpg',
            beneficiaries: ['Beneficiary 1', 'Beneficiary 2'],
            budgetDetails: [['amount' => 500.0, 'description' => 'Budget item 1']],
            campaignCount: 5,
            categories: ['Category 1', 'Category 2'],
            championName: 'Champion Name',
            championOptInStatement: 'Champion opt-in statement',
            championRef: 'championRef123',
            charity: $charity,
            countries: ['Country 1', 'Country 2'],
            currencyCode: 'GBP',
            donationCount: 10,
            endDate: '2023-12-31T23:59:59.000Z',
            hidden: false,
            impactReporting: 'Impact reporting',
            impactSummary: 'Impact summary',
            isMatched: true,
            logoUri: 'https://example.com/logo.jpg',
            matchFundsRemaining: 500.0,
            matchFundsTotal: 1000.0,
            parentAmountRaised: 2000.0,
            parentDonationCount: 20,
            parentMatchFundsRemaining: 1000.0,
            parentRef: 'parentRef123',
            parentTarget: 5000.0,
            parentUsesSharedFunds: true,
            problem: 'Problem description',
            quotes: [['person' => 'Person 1', 'quote' => 'Quote 1']],
            ready: true,
            solution: 'Solution description',
            startDate: '2023-01-01T00:00:00.000Z',
            status: 'Active',
            isRegularGiving: true,
            regularGivingCollectionEnd: '2024-12-31T23:59:59.000Z',
            summary: 'Campaign summary',
            surplusDonationInfo: 'Surplus donation info',
            target: 10000.0,
            thankYouMessage: 'Thank you for your donation',
            title: 'Campaign Title',
            updates: [['content' => 'Update 1', 'modifiedDate' => '2023-06-01T12:00:00.000Z']],
            usesSharedFunds: true,
            video: ['provider' => 'youtube', 'key' => 'videoId123'],
        );

        // Verify that a few key properties were set correctly
        $this->assertSame('campaignId123', $campaign->id);
        $this->assertSame(1000.50, $campaign->amountRaised);
        $this->assertSame('Test Charity', $campaign->charity->name);
    }
}
