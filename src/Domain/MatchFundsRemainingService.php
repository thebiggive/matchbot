<?php

namespace MatchBot\Domain;

use MatchBot\Domain\Campaign as CampaignDomainModel;

class MatchFundsRemainingService
{
    /**
     * should match SF Current_Match_Funds_Available__c
     */
    public function getFundsRemaining(CampaignDomainModel $campaign): float
    {
        $matchFundsRemaining = $campaign->getSalesforceData()['matchFundsRemaining'];

        \assert(\is_float($matchFundsRemaining));

        return $matchFundsRemaining;
    }
}
