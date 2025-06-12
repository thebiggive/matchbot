<?php

namespace MatchBot\IntegrationTests;

use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\Currency;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundType;
use MatchBot\Domain\MatchFundsService;
use MatchBot\Domain\MetaCampaign;
use MatchBot\Domain\Money;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;

class MatchFundsServiceTest extends IntegrationTest
{
    private MatchFundsService $sut;
    private MetaCampaign $metaCampaign;
    private Campaign $campaign1;
    private Campaign $campaign2;
    private Fund $fund1;
    private Fund $fund2;
    private CampaignFunding $campaignFunding1;
    private CampaignFunding $campaignFunding2;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->sut = $this->getService(MatchFundsService::class);

        // Create a meta campaign
        $this->metaCampaign = TestCase::someMetaCampaign(false, false);

        // Create two campaigns under the meta campaign
        $this->campaign1 = TestCase::someCampaign(metaCampaignSlug: $this->metaCampaign->getSlug());
        $this->campaign2 = TestCase::someCampaign(metaCampaignSlug: $this->metaCampaign->getSlug());

        // Create two funds
        $this->fund1 = new Fund(
            currencyCode: 'GBP',
            name: 'Test Match Fund 1',
            salesforceId: Salesforce18Id::ofFund(TestCase::randomString()),
            fundType: FundType::ChampionFund
        );

        $this->fund2 = new Fund(
            currencyCode: 'GBP',
            name: 'Test Match Fund 2',
            salesforceId: Salesforce18Id::ofFund(TestCase::randomString()),
            fundType: FundType::ChampionFund
        );

        // Create campaign fundings
        $this->campaignFunding1 = new CampaignFunding($this->fund1, amount: '100.00', amountAvailable: '100.00');
        $this->campaignFunding1->addCampaign($this->campaign1);

        $this->campaignFunding2 = new CampaignFunding($this->fund2, amount: '200.00', amountAvailable: '200.00');
        $this->campaignFunding2->addCampaign($this->campaign2);

        // Persist everything
        $this->em->persist($this->metaCampaign);
        $this->em->persist($this->campaign1);
        $this->em->persist($this->campaign2);
        $this->em->persist($this->fund1);
        $this->em->persist($this->fund2);
        $this->em->persist($this->campaignFunding1);
        $this->em->persist($this->campaignFunding2);

        // Create another meta campaign with its own campaign and fund to ensure we correctly ignore it
        $otherMetaCampaign = TestCase::someMetaCampaign(false, false);
        $otherCampaign = TestCase::someCampaign(metaCampaignSlug: $otherMetaCampaign->getSlug());
        $otherFund = new Fund(
            currencyCode: 'GBP',
            name: 'Other Test Match Fund',
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

    public function testItFindsCorrectTotalOfAvailableFunds(): void
    {
        $fundsRemaining = $this->sut->getFundsRemainingForMetaCampaign($this->metaCampaign);

        // Should be 100.00 + 200.00 = 300.00
        $this->assertEquals(Money::fromPoundsGBP(300), $fundsRemaining);
    }

    public function testItFindsCorrectTotalWhenSomeFundsAreUsed(): void
    {
        // Reduce the available amount in one of the fundings
        $this->campaignFunding1->setAmountAvailable('50.00');
        $this->em->flush();

        $fundsRemaining = $this->sut->getFundsRemainingForMetaCampaign($this->metaCampaign);

        // Should be 50.00 + 200.00 = 250.00
        $this->assertEquals(Money::fromPoundsGBP(250), $fundsRemaining);
    }

    public function testItFindsZeroWhenNoFundsAreAvailable(): void
    {
        // Set both fundings to have zero available
        $this->campaignFunding1->setAmountAvailable('0.00');
        $this->campaignFunding2->setAmountAvailable('0.00');
        $this->em->flush();

        $fundsRemaining = $this->sut->getFundsRemainingForMetaCampaign($this->metaCampaign);

        // Should be 0.00
        $this->assertEquals(Money::fromPoundsGBP(0), $fundsRemaining);
    }

    public function testItHandlesCurrencyMismatchCorrectly(): void
    {
        // Create a fund with a different currency
        $eurFund = new Fund(
            currencyCode: 'EUR',
            name: 'Euro Test Match Fund',
            salesforceId: Salesforce18Id::ofFund(TestCase::randomString()),
            fundType: FundType::ChampionFund
        );

        // Create a campaign under the meta campaign
        $campaign = TestCase::someCampaign(metaCampaignSlug: $this->metaCampaign->getSlug());

        // Create a funding with EUR currency for the GBP campaign
        $eurCampaignFunding = new CampaignFunding($eurFund, amount: '150.00', amountAvailable: '150.00');
        $eurCampaignFunding->addCampaign($campaign);

        $this->em->persist($eurFund);
        $this->em->persist($campaign);
        $this->em->persist($eurCampaignFunding);
        $this->em->flush();

        // This should throw an assertion error because the fund currency (EUR) doesn't match
        // the meta campaign's currency (GBP)
        $this->expectException(\Assert\AssertionFailedException::class);
        $this->sut->getFundsRemainingForMetaCampaign($this->metaCampaign);
    }
}
