<?php

namespace MatchBot\IntegrationTests;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignService;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundingWithdrawal;
use MatchBot\Domain\FundType;
use MatchBot\Domain\Money;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;

class CampaignServiceTest extends IntegrationTest
{
    private CampaignService $SUT;

    #[\Override]
    public function setUp(): void
    {
        parent::setUp();
        $this->SUT = $this->getService(CampaignService::class);
    }

    public function testACampaignWithNoDonationsRaisedNoMoney(): void
    {
        $campaign = TestCase::someCampaign();
        $em = $this->getService(EntityManagerInterface::class);
        $em->persist($campaign);
        $em->flush();

        $campaignId = $campaign->getId();
        \assert($campaignId !== null);

        $this->assertEquals(
            $this->SUT->cachedAmountRaised($campaignId),
            Money::zero(),
        );
    }

    public function testCampaignWithDonationsAndMatchedFundsRaisedDoubleMoney(): void
    {
        $fund = new Fund(
            currencyCode: 'GBP',
            name: 'Test Match Fund',
            salesforceId: Salesforce18Id::ofFund(TestCase::randomString()),
            fundType: FundType::ChampionFund
        );

        $campaign = TestCase::someCampaign();
        $campaignFunding = new CampaignFunding($fund, amount: '100.00', amountAvailable: '100.00');
        $donation = TestCase::someDonation(campaign: $campaign, amount: '2.0', collected: true);
        $fundingWithdrawal = new FundingWithdrawal($campaignFunding);

        $fundingWithdrawal->setDonation($donation);
        $fundingWithdrawal->setAmount('2.00');

        $em = $this->getService(EntityManagerInterface::class);

        $em->persist($fund);
        $em->persist($campaign);
        $em->persist($campaignFunding);
        $em->persist($donation);
        $em->persist($fundingWithdrawal);

        $em->flush();

        $campaignId = $campaign->getId();
        \assert($campaignId !== null);

        $this->assertEquals(
            Money::fromPoundsGBP(4),
            $this->SUT->cachedAmountRaised($campaignId),
        );
    }

    public function testCampaignWithDonationsNoMatchedFundsRaisedMoney(): void
    {
        $campaign = TestCase::someCampaign();
        $donation = TestCase::someDonation(campaign: $campaign, amount: '2.0', collected: true);

        $em = $this->getService(EntityManagerInterface::class);

        $em->persist($campaign);
        $em->persist($donation);

        $em->flush();

        $campaignId = $campaign->getId();
        \assert($campaignId !== null);

        $this->assertEquals(
            $this->SUT->cachedAmountRaised($campaignId),
            Money::fromPoundsGBP(2),
        );
    }
}
