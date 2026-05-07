<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignService;
use MatchBot\Domain\CampaignStatus;
use MatchBot\Domain\Currency;
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
            name: 'Test campaign',
            summary: 'Test Campaign Summary',
            currencyCode: 'GBP',
            isRegularGiving: false,
            pinPosition: null,
            championPagePinPosition: null,
            relatedApplicationStatus: null,
            relatedApplicationCharityResponseToOffer: null,
            regularGivingCollectionEnd: null,
            totalFundraisingTarget: Money::zero(),
            thankYouMessage: null,
            rawData: [],
            hidden: false,
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
            name: 'Test campaign',
            summary: 'Test Campaign Summary',
            currencyCode: 'GBP',
            isRegularGiving: false,
            pinPosition: null,
            championPagePinPosition: null,
            relatedApplicationStatus: null,
            relatedApplicationCharityResponseToOffer: null,
            regularGivingCollectionEnd: null,
            totalFundraisingTarget: Money::zero(),
            thankYouMessage: null,
            rawData: [],
            hidden: false,
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
            name: 'Test campaign',
            summary: 'Test Campaign Summary',
            currencyCode: 'GBP',
            isRegularGiving: false,
            pinPosition: null,
            championPagePinPosition: null,
            relatedApplicationStatus: null,
            relatedApplicationCharityResponseToOffer: null,
            regularGivingCollectionEnd: null,
            totalFundraisingTarget: Money::zero(),
            thankYouMessage: null,
            rawData: [],
            hidden: false,
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
            name: 'campaign name',
            summary: 'Test Campaign Summary',
            currencyCode: 'GBP',
            isRegularGiving: false,
            pinPosition: null,
            championPagePinPosition: null,
            relatedApplicationStatus: null,
            relatedApplicationCharityResponseToOffer: null,
            regularGivingCollectionEnd: null,
            totalFundraisingTarget: Money::zero(),
            thankYouMessage: null,
            rawData: [],
            hidden: false,
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

    public function testCampaignStatusIsBasedOnDate(): void
    {
        $campaign = self::someCampaign();
        $campaign->setStartDate(new \DateTimeImmutable('2026-01-01T12:00:00'));
        $campaign->setEndDate(new \DateTimeImmutable('2027-01-01T12:00:00'));

        $this->assertSame(CampaignStatus::Preview, $campaign->getStatus(new \DateTimeImmutable('2026-01-01T11:59:59')));
        $this->assertSame(CampaignStatus::Active, $campaign->getStatus(new \DateTimeImmutable('2026-01-01T12:00:00')));
        $this->assertSame(CampaignStatus::Active, $campaign->getStatus(new \DateTimeImmutable('2027-01-01T12:00:00')));
        $this->assertSame(CampaignStatus::Expired, $campaign->getStatus(new \DateTimeImmutable('2027-01-01T12:00:01')));
    }
}
