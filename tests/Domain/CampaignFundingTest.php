<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundType;
use MatchBot\Tests\TestCase;

class CampaignFundingTest extends TestCase
{
    public function testItAddsDistinctCampaignsOnly(): void
    {
        $campaignOne = self::someCampaign();
        $campaignTwo = self::someCampaign();
        // all constructor params are irrelevant here:
        $campaignFunding = new CampaignFunding(new Fund('GBP', 'some pledge', null, null, fundType: FundType::Pledge), '1', '1');

        $campaignFunding->addCampaign($campaignOne);
        $campaignFunding->addCampaign($campaignTwo);
        $campaignFunding->addCampaign($campaignTwo);

        $this->assertSame([$campaignOne, $campaignTwo], $campaignFunding->getCampaigns());
    }
}
