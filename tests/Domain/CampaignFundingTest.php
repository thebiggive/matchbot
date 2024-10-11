<?php

namespace Domain;

use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\Pledge;
use MatchBot\Tests\TestCase;

class CampaignFundingTest extends TestCase
{
    public function testItAddsDistinctCampaignsOnly(): void
    {
        $campaignOne = self::someCampaign();
        $campaignTwo = self::someCampaign();
        // all constructor params are irrelevant here:
        $campaignFunding = new CampaignFunding(new Pledge('GBP', 'some pledge'), '1', '1', 1);

        $campaignFunding->addCampaign($campaignOne);
        $campaignFunding->addCampaign($campaignTwo);
        $campaignFunding->addCampaign($campaignTwo);

        $this->assertSame([$campaignOne, $campaignTwo], $campaignFunding->getCampaigns());
    }
}
