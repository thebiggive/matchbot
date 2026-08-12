<?php

namespace MatchBot\IntegrationTests;

use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\Currency;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundType;
use MatchBot\Domain\MatchFundsService;
use MatchBot\Domain\MetaCampaign;
use MatchBot\Domain\Money;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;

/**
 * Tests for the MatchFundsService which calculates the total amount of match funds
 * available for a meta campaign.
 *
 * These tests verify that the service correctly:
 * - Adds up all available funds across multiple campaigns
 * - Accounts for funds that have been partially used
 * - Handles the case when all funds have been used
 * - Validates that fund currencies match the meta campaign currency
 */
class MatchFundsServiceTest extends IntegrationTest
{
    private MatchFundsService $sut;
    private DonationService $donationService;
    private MetaCampaign $testMetaCampaign;
    private Campaign $firstCampaign;
    private Campaign $secondCampaign;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->sut = $this->getService(MatchFundsService::class);
        $this->donationService = $this->getService(DonationService::class);

        $this->testMetaCampaign = TestCase::someMetaCampaign(false, false);
        $this->firstCampaign = TestCase::someCampaign(metaCampaignSlug: $this->testMetaCampaign->getSlug());
        $this->secondCampaign = TestCase::someCampaign(metaCampaignSlug: $this->testMetaCampaign->getSlug());

        $this->firstCampaign->setIsMatched(true);
        $this->secondCampaign->setIsMatched(true);
        $this->em->persist($this->testMetaCampaign);
        $this->em->persist($this->firstCampaign);
        $this->em->persist($this->secondCampaign);

        // Create another meta campaign with its own campaign and fund to ensure we correctly ignore it
        $otherMetaCampaign = TestCase::someMetaCampaign(false, false);
        $otherCampaign = TestCase::someCampaign(metaCampaignSlug: $otherMetaCampaign->getSlug());
        $otherFund = new Fund(
            currencyCode: 'GBP',
            name: 'Other Test Match Fund',
            slug: null,
            salesforceId: Salesforce18Id::ofFund(TestCase::randomString()),
            fundType: FundType::ChampionFund
        );
        $otherCampaignFunding = new CampaignFunding($otherFund, amount: '300.00', amountAvailable: '300.00');
        $otherCampaignFunding->addCampaign($otherCampaign);

        $this->em->persist($otherMetaCampaign);
        $this->em->persist($otherCampaign);
        $this->em->persist($otherFund);
        $this->em->persist($otherCampaignFunding);

        $this->em->flush();
    }

    /**
     * Test that the service correctly adds up all available funds across multiple campaigns.
     *
     * In this scenario:
     * - We have a small fund of £100 for the first campaign
     * - We have a large fund of £200 for the second campaign
     * - The total available funds should be £300
     */
    public function testItFindsCorrectTotalOfAvailableFunds(): void
    {
        $smallFundAmount = '100.00';
        $largeFundAmount = '200.00';
        $expectedTotal = 300;

        $this->setupTwoFundsForTesting($smallFundAmount, $largeFundAmount);

        $fundsRemaining = $this->sut->getFundsRemainingForMetaCampaign($this->testMetaCampaign);
        $this->assertEquals(Money::fromPoundsGBP($expectedTotal), $fundsRemaining);
    }

    /**
     * Test that the service correctly accounts for funds that have been partially used.
     *
     * In this scenario:
     * - We have a small fund of £100 for the first campaign
     * - We have a large fund of £200 for the second campaign
     * - A donation of £50 is made to the first campaign, using £50 from the small fund
     * - The total available funds should be £250 (£50 remaining from small fund + £200 from large fund)
     */
    public function testItFindsCorrectTotalWhenSomeFundsAreUsed(): void
    {
        $smallFundAmount = '100.00';
        $largeFundAmount = '200.00';
        $donationAmount = '50.00';
        $expectedTotal = 250;

        $this->setupTwoFundsForTesting($smallFundAmount, $largeFundAmount);

        $donation = TestCase::someDonation(
            amount: $donationAmount,
            giftAid: false,
            campaign: $this->firstCampaign,
            collected: true
        );

        $this->donationService->enrollNewDonation($donation, attemptMatching: true, dispatchUpdateMessage: false);

        $fundsRemaining = $this->sut->getFundsRemainingForMetaCampaign($this->testMetaCampaign);
        $this->assertEquals(Money::fromPoundsGBP($expectedTotal), $fundsRemaining);
    }

    /**
     * Test that the service correctly handles the case when all funds have been used.
     *
     * In this scenario:
     * - We have a small fund of £100 for the first campaign
     * - We have a large fund of £200 for the second campaign
     * - A donation of £100 is made to the first campaign, using all of the small fund
     * - A donation of £200 is made to the second campaign, using all of the large fund
     * - The total available funds should be £0
     */
    public function testItFindsZeroWhenNoFundsAreAvailable(): void
    {
        $smallFundAmount = '100.00';
        $largeFundAmount = '200.00';
        $firstDonationAmount = '100.00';
        $secondDonationAmount = '200.00';
        $expectedTotal = 0;

        $this->setupTwoFundsForTesting($smallFundAmount, $largeFundAmount);

        $donation1 = TestCase::someDonation(
            amount: $firstDonationAmount,
            giftAid: false,
            campaign: $this->firstCampaign,
            collected: true
        );

        $donation2 = TestCase::someDonation(
            amount: $secondDonationAmount,
            giftAid: false,
            campaign: $this->secondCampaign,
            collected: true
        );

        $this->donationService->enrollNewDonation($donation1, attemptMatching: true, dispatchUpdateMessage: false);
        $this->donationService->enrollNewDonation($donation2, attemptMatching: true, dispatchUpdateMessage: false);

        $fundsRemaining = $this->sut->getFundsRemainingForMetaCampaign($this->testMetaCampaign);
        $this->assertEquals(Money::fromPoundsGBP($expectedTotal), $fundsRemaining);
    }

    /**
     * Test that the service correctly validates that fund currencies match the meta campaign currency.
     *
     * In this scenario:
     * - We have a GBP fund of £100 for the first campaign
     * - We create a EUR fund of €150 for a new campaign
     * - When we try to get the total available funds, an exception should be thrown
     *   because the fund currency (EUR) doesn't match the meta campaign currency (GBP)
     */
    public function testItHandlesCurrencyMismatchCorrectly(): void
    {
        $gbpFundAmount = '100.00';
        $eurFundAmount = '150.00';

        $gbpFund = $this->createFund("GBP Fund (£{$gbpFundAmount})");
        $gbpFundingForFirstCampaign = $this->createCampaignFunding(
            $gbpFund,
            $this->firstCampaign,
            $gbpFundAmount,
            $gbpFundAmount
        );

        $eurFund = $this->createFund("Euro Fund (€{$eurFundAmount})", 'EUR');

        $eurCampaign = TestCase::someCampaign(metaCampaignSlug: $this->testMetaCampaign->getSlug());

        $eurFundingForNewCampaign = $this->createCampaignFunding(
            $eurFund,
            $eurCampaign,
            $eurFundAmount,
            $eurFundAmount
        );

        $this->em->persist($gbpFund);
        $this->em->persist($eurFund);
        $this->em->persist($eurCampaign);
        $this->em->persist($gbpFundingForFirstCampaign);
        $this->em->persist($eurFundingForNewCampaign);
        $this->em->flush();

        // This should throw an assertion error because the fund currency (EUR) doesn't match
        // the meta campaign's currency (GBP)
        $this->expectException(\Assert\AssertionFailedException::class);
        $this->sut->getFundsRemainingForMetaCampaign($this->testMetaCampaign);
    }

    /**
     * Creates a fund with the specified amount and name
     */
    private function createFund(string $name, string $currencyCode = 'GBP'): Fund
    {
        return new Fund(
            currencyCode: $currencyCode,
            name: $name,
            slug: null,
            salesforceId: Salesforce18Id::ofFund(TestCase::randomString()),
            fundType: FundType::ChampionFund
        );
    }

    /**
     * Creates a campaign funding with the specified amount and links it to a campaign
     *
     * @param numeric-string $amount
     * @param numeric-string $amountAvailable
     */
    private function createCampaignFunding(Fund $fund, Campaign $campaign, string $amount, string $amountAvailable): CampaignFunding
    {
        $funding = new CampaignFunding($fund, amount: $amount, amountAvailable: $amountAvailable);
        $funding->addCampaign($campaign);
        return $funding;
    }

    /**
     * Sets up two funds with specific amounts for the test campaigns
     *
     * @param numeric-string $smallFundAmount
     * @param numeric-string $largeFundAmount
     */
    private function setupTwoFundsForTesting(string $smallFundAmount, string $largeFundAmount): void
    {
        $smallFund = $this->createFund("Small Fund (£{$smallFundAmount})");
        $largeFund = $this->createFund("Large Fund (£{$largeFundAmount})");

        $smallFundingForFirstCampaign = $this->createCampaignFunding(
            $smallFund,
            $this->firstCampaign,
            $smallFundAmount,
            $smallFundAmount
        );

        $largeFundingForSecondCampaign = $this->createCampaignFunding(
            $largeFund,
            $this->secondCampaign,
            $largeFundAmount,
            $largeFundAmount
        );

        $this->em->persist($smallFund);
        $this->em->persist($largeFund);
        $this->em->persist($smallFundingForFirstCampaign);
        $this->em->persist($largeFundingForSecondCampaign);
        $this->em->flush();
    }
}
