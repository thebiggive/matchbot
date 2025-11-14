<?php

namespace MatchBot\IntegrationTests;

use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\Currency;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\FundType;
use MatchBot\Domain\MetaCampaign;
use MatchBot\Domain\MetaCampaignRepository;
use MatchBot\Domain\MetaCampaignSlug;
use MatchBot\Domain\Money;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;

class MetaCampaignRepositoryTest extends IntegrationTest
{
    private MetaCampaignRepository $sut;
    private MetaCampaign $metaCampaign;
    private Campaign $campaign;
    private Fund $fund;
    private CampaignFunding $campaignFunding;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->sut = $this->getService(MetaCampaignRepository::class);

        $this->fund = new Fund(
            currencyCode: 'GBP',
            name: 'Test Match Fund',
            slug: null,
            salesforceId: Salesforce18Id::ofFund(TestCase::randomString()),
            fundType: FundType::ChampionFund
        );

        $this->metaCampaign = TestCase::someMetaCampaign(false, false);
        $this->campaign = TestCase::someCampaign(metaCampaignSlug: $this->metaCampaign->getSlug());

        $this->campaignFunding = new CampaignFunding($this->fund, amount: '100.00', amountAvailable: '100.00');

        $this->em->persist($this->campaign);
        $this->em->persist($this->metaCampaign);
        $this->em->persist($this->fund);
        $this->em->persist($this->campaignFunding);

        // put another campaign and donation in the DB to make sure we correctly ignore it in our calculations
        $otherMetaCampaign = TestCase::someMetaCampaign(false, false);
        $otherCampaign = TestCase::someCampaign(metaCampaignSlug: $otherMetaCampaign->getSlug());
        $donation = TestCase::someDonation(amount: (string) random_int(1, 500), giftAid: false, campaign: $otherCampaign, collected: true);

        $this->em->persist($otherMetaCampaign);
        $this->em->persist($otherCampaign);
        $this->em->persist($donation);

        $this->em->flush();
    }

    public function testItFindsNothingRaisedWhenNoDonations(): void
    {
        $amountRaised = $this->sut->totalAmountRaised($this->metaCampaign);

        $this->assertEquals(Money::fromPoundsGBP(0), $amountRaised);
    }

    public function testItFindsTotalOfOneDonationNoGA(): void
    {
        $donation = TestCase::someDonation(amount: '47.00', giftAid: false, campaign: $this->campaign, collected: true);

        $this->em->persist($donation);
        $this->em->flush();

        $amountRaised = $this->sut->totalAmountRaised($this->metaCampaign);

        // 47 = 47
        $this->assertEquals(Money::fromPoundsGBP(47), $amountRaised);
    }

    public function testItFindsTotalOfOneDonationWithGA(): void
    {
        $donation = TestCase::someDonation(amount: '47.00', giftAid: true, campaign: $this->campaign, collected: true);

        $this->em->persist($donation);
        $this->em->flush();

        $amountRaised = $this->sut->totalAmountRaised($this->metaCampaign);

        // 58.75 = 47 * 1.25
        $this->assertEquals(Money::fromPence(58_75, Currency::GBP), $amountRaised);
    }


    public function testItFindsTotalOfOneDonationWithNoGAPartlyMatched(): void
    {
        $donation = TestCase::someDonation(amount: '47.00', giftAid: false, campaign: $this->campaign, collected: true);

        $fundingWithdrawal = new FundingWithdrawal($this->campaignFunding, $donation, '10.00');

        $this->em->persist($donation);
        $this->em->persist($fundingWithdrawal);
        $this->em->flush();

        $amountRaised = $this->sut->totalAmountRaised($this->metaCampaign);

        // 57 = 47 + 10
        $this->assertEquals(Money::fromPence(57_00, Currency::GBP), $amountRaised);
    }

    public function testItPersistsMetaCampaignMoneyChange(): void
    {
        $metacampaign = TestCase::someMetaCampaign(false, false, slug: MetaCampaignSlug::of(TestCase::randomString()));
        $slug = $metacampaign->getSlug();
        $this->em->persist($metacampaign);
        $this->em->flush();

        $metacampaign->setMatchFundsTotal(Money::fromNumericString('25000000', Currency::GBP));

        $this->em->flush();
        $this->em->clear();

        unset($metacampaign);

        $persistedMetaCampaign = $this->sut->getBySlug($slug);

        $this->assertSame('£25,000,000.00', $persistedMetaCampaign->getMatchFundsTotal()->format());
    }
}
