<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\Campaign;
use MatchBot\Domain\DomainException\WrongCampaignType;
use MatchBot\Domain\Money;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;

class CampaignTest extends TestCase
{
    public function testOpenCampaign(): void
    {
        $campaign = new Campaign(
            sfId: Salesforce18Id::ofCampaign('xxxxxxxxxxxxxxxxxx'),
            metaCampaignSlug: null,
            charity: TestCase::someCharity(),
            startDate: new \DateTimeImmutable('2020-01-01'),
            endDate: new \DateTimeImmutable('2030-12-31'),
            isMatched: true,
            ready: true,
            status: null,
            name: 'Test campaign',
            currencyCode: 'GBP',
            isRegularGiving: false,
            regularGivingCollectionEnd: null,
            thankYouMessage: null,
            rawData: [],
            hidden: false,
            totalFundingAllocation: Money::zero(),
            amountPledged: Money::zero(),
        );

        $this->assertTrue($campaign->isOpen(new \DateTimeImmutable('2025-01-01')));
    }
    public function testNonReadyCampaignIsNotOpen(): void
    {
        $campaign = new Campaign(
            sfId: Salesforce18Id::ofCampaign('xxxxxxxxxxxxxxxxxx'),
            metaCampaignSlug: null,
            charity: TestCase::someCharity(),
            startDate: new \DateTimeImmutable('2020-01-01'),
            endDate: new \DateTimeImmutable('2030-12-31'),
            isMatched: true,
            ready: false,
            status: null,
            name: 'Test campaign',
            currencyCode: 'GBP',
            isRegularGiving: false,
            regularGivingCollectionEnd: null,
            thankYouMessage: null,
            rawData: [],
            hidden: false,
            totalFundingAllocation: Money::zero(),
            amountPledged: Money::zero(),
        );

        $this->assertFalse($campaign->isOpen(at: new \DateTimeImmutable('2025-01-01')));
    }

    public function testCampaignIsNotOpenBeforeStartDate(): void
    {
        $campaign = new Campaign(
            sfId: Salesforce18Id::ofCampaign('xxxxxxxxxxxxxxxxxx'),
            metaCampaignSlug: null,
            charity: TestCase::someCharity(),
            startDate: new \DateTimeImmutable('2020-01-01'),
            endDate: new \DateTimeImmutable('2030-12-31'),
            isMatched: true,
            ready: true,
            status: null,
            name: 'Test campaign',
            currencyCode: 'GBP',
            isRegularGiving: false,
            regularGivingCollectionEnd: null,
            thankYouMessage: null,
            rawData: [],
            hidden: false,
            totalFundingAllocation: Money::zero(),
            amountPledged: Money::zero(),
        );

        $this->assertFalse($campaign->isOpen(at: new \DateTimeImmutable('2019-12-31T23:59:59')));
    }

    public function testCampaignIsNotOpenAtEndDate(): void
    {
        $campaign = new Campaign(
            sfId: Salesforce18Id::ofCampaign('xxxxxxxxxxxxxxxxxx'),
            metaCampaignSlug: null,
            charity: TestCase::someCharity(),
            startDate: new \DateTimeImmutable('2020-01-01'),
            endDate: new \DateTimeImmutable('2030-12-31'),
            isMatched: true,
            ready: true,
            status: null,
            name: 'campaign name',
            currencyCode: 'GBP',
            isRegularGiving: false,
            regularGivingCollectionEnd: null,
            thankYouMessage: null,
            rawData: [],
            hidden: false,
            totalFundingAllocation: Money::zero(),
            amountPledged: Money::zero(),
        );

        $this->assertFalse($campaign->isOpen(at: new \DateTimeImmutable('2030-12-31')));
    }

    public function testReadyToAcceptAdHocDonation(): void
    {
        $date = new \DateTimeImmutable('2025-08-01');
        $campaign = self::someCampaign();

        $this->expectNotToPerformAssertions();

        try {
            $campaign->checkIsReadyToAcceptDonation(self::someDonation(campaign: $campaign), $date);
        } catch (\Exception $e) {
            $this->fail($e->getMessage());
        }
    }

    public function testRegularGivingCampaignDoesNotAcceptAdHocDonation(): void
    {
        $date = new \DateTimeImmutable('2025-08-01');

        $campaign = self::someCampaign(isRegularGiving: true);

        $this->expectException(WrongCampaignType::class);
        $campaign->checkIsReadyToAcceptDonation(self::someDonation(campaign: $campaign), $date);
    }


    public function testAdHocGivingCampaignDoesNotAcceptRegularGivingDonation(): void
    {
        $date = new \DateTimeImmutable('2025-08-01');

        $campaign = self::someCampaign(isRegularGiving: false);

        $this->expectException(WrongCampaignType::class);
        $campaign->checkIsReadyToAcceptDonation(self::someDonation(
            regularGivingMandate: $this->createStub(RegularGivingMandate::class),
            campaign: $campaign
        ), $date);
    }
}
