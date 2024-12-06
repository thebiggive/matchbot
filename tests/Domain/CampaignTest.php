<?php

namespace Domain;

use MatchBot\Domain\Campaign;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\TestCase;

class CampaignTest extends TestCase
{
    public function testOpenCampaign(): void
    {
        $campaign = new Campaign(
            sfId: Salesforce18Id::ofCampaign('xxxxxxxxxxxxxxxxxx'),
            charity: TestCase::someCharity(),
            startDate: new \DateTimeImmutable('2020-01-01'),
            endDate: new \DateTimeImmutable('2030-12-31'),
            isMatched: true,
            ready: true,
            status: 'status',
            name: 'Test campaign',
            currencyCode: 'GBP',
            isRegularGiving: false,
            regularGivingCollectionEnd: null,
        );

        $this->assertTrue($campaign->isOpen(new \DateTimeImmutable('2025-01-01')));
    }
    public function testNonReadyCampaignIsNotOpen(): void
    {
        $campaign = new Campaign(
            sfId: Salesforce18Id::ofCampaign('xxxxxxxxxxxxxxxxxx'),
            charity: TestCase::someCharity(),
            startDate: new \DateTimeImmutable('2020-01-01'),
            endDate: new \DateTimeImmutable('2030-12-31'),
            isMatched: true,
            ready: false,
            status: 'status',
            name: 'Test campaign',
            currencyCode: 'GBP',
            isRegularGiving: false,
            regularGivingCollectionEnd: null,
        );

        $this->assertFalse($campaign->isOpen(at: new \DateTimeImmutable('2025-01-01')));
    }

    public function testCampaignIsNotOpenBeforeStartDate(): void
    {
        $campaign = new Campaign(
            sfId: Salesforce18Id::ofCampaign('xxxxxxxxxxxxxxxxxx'),
            charity: TestCase::someCharity(),
            startDate: new \DateTimeImmutable('2020-01-01'),
            endDate: new \DateTimeImmutable('2030-12-31'),
            isMatched: true,
            ready: true,
            status: 'status',
            name: 'Test campaign',
            currencyCode: 'GBP',
            isRegularGiving: false,
            regularGivingCollectionEnd: null,
        );

        $this->assertFalse($campaign->isOpen(at: new \DateTimeImmutable('2019-12-31T23:59:59')));
    }

    public function testCampaignIsNotOpenAtEndDate(): void
    {
        $campaign = new Campaign(
            sfId: Salesforce18Id::ofCampaign('xxxxxxxxxxxxxxxxxx'),
            charity: TestCase::someCharity(),
            startDate: new \DateTimeImmutable('2020-01-01'),
            endDate: new \DateTimeImmutable('2030-12-31'),
            isMatched: true,
            ready: true,
            status: 'status',
            name: 'campaign name',
            currencyCode: 'GBP',
            isRegularGiving: false,
            regularGivingCollectionEnd: null,
        );

        $this->assertFalse($campaign->isOpen(at: new \DateTimeImmutable('2030-12-31')));
    }
}
