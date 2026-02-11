<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignService;
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
            status: null,
            name: 'Test campaign',
            summary: 'Test Campaign Summary',
            currencyCode: 'GBP',
            totalFundingAllocation: Money::zero(),
            amountPledged: Money::zero(),
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
            status: null,
            name: 'Test campaign',
            summary: 'Test Campaign Summary',
            currencyCode: 'GBP',
            totalFundingAllocation: Money::zero(),
            amountPledged: Money::zero(),
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
            status: null,
            name: 'Test campaign',
            summary: 'Test Campaign Summary',
            currencyCode: 'GBP',
            totalFundingAllocation: Money::zero(),
            amountPledged: Money::zero(),
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
            status: null,
            name: 'campaign name',
            summary: 'Test Campaign Summary',
            currencyCode: 'GBP',
            totalFundingAllocation: Money::zero(),
            amountPledged: Money::zero(),
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

    /** @dataProvider targetDataProvider */
    public function testTarget(
        bool $metaCampaignIsEmergencyIMF,
        int $metaCampaignTarget,
        bool $isMatched,
        int $totalFundRaisingTarget,
        int $amountPledged,
        int $totalFundingAllocation,
        int $expectedTarget
    ): void {
        $metaCampaign = TestCase::someMetaCampaign(
            isRegularGiving: false,
            isEmergencyIMF: $metaCampaignIsEmergencyIMF,
            imfCampaignTargetOverride: Money::fromPence($metaCampaignTarget, Currency::GBP),
            matchFundsTotal: Money::zero(),
        );

        $campaign = self::someCampaign(
            isMatched: $isMatched,
            totalFundraisingTarget: Money::fromPence($totalFundRaisingTarget, Currency::GBP),
            amountPledged: Money::fromPence($amountPledged, Currency::GBP),
            totalFundingAllocation: Money::fromPence($totalFundingAllocation, Currency::GBP),
            metaCampaignSlug: $metaCampaign->getSlug(),
        );

        $target = CampaignService::target($campaign, $metaCampaign);

        $this->assertEquals(Money::fromPence($expectedTarget, Currency::GBP), $target);
    }

    /**
     * @return array<string, array{0: bool, 1: int, 2: bool, 3: int, 4: int, 5: int, 6: int}>
     */
    public function targetDataProvider(): array
    {
        // all amounts in pence
        //
        //   $metaCampaignIsEmergencyIMF, $metaCampaignTarget, $isMatched,
        //   $totalFundRaisingTarget, $amountPledged, $totalFundingAllocation,
        //   $expectedTarget
        return [
            'nothing will come of nothing' => [
                false, 0_00, false,
                0_00, 0_00, 0_00,
                0_00
            ],
            'uses emergency meta-campaign target' => [
                true, 56_00, false,
                12_00, 0_00, 0_00,
                56_00
            ],
            'uses totalFundRaisingTarget for non-emergency target' => [
                false, 56_00, false,
                12_00, 0_00, 0_00,
                12_00
            ],
            'for matched campaign, uses double sum of pledges and funding' => [
                false, 56_00, true,
                12_00, 150_00, 50_00, // unusual case having 150 and 50 not equal, but covers general case.
                400_00,
            ],
        ];
    }
}
