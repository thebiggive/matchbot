<?php

namespace MatchBot\Tests\Domain;

use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignRenderCompatibilityChecker;
use MatchBot\Domain\Fund;
use MatchBot\Domain\FundType;
use MatchBot\Tests\TestCase;

class CampaignRenderCompatibilityCheckerTest extends TestCase
{
    /**
     * No assertions as we just check our SUT does not throw exception.
     *
     * @doesNotPerformAssertions
     */
    public function testMatchbotAmountRaisedIsCompatibleWithSalesforce(): void
    {
        // arrange
        $actual = self::CAMPAIGN_FROM_SALESFORCE;

        $actual['amountRaised'] += 1.0; // simulates a donation in MB that hasn't been rolled up in SF yet.
        unset($actual['charity']['postalAddress']); // We don't send postal address to FE, just used for sending emails

        // act
        CampaignRenderCompatibilityChecker::checkCampaignHttpModelMatchesModelFromSF($actual, self::CAMPAIGN_FROM_SALESFORCE);
    }
}
