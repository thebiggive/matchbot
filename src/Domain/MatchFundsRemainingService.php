<?php

namespace MatchBot\Domain;

use MatchBot\Domain\Campaign as CampaignDomainModel;

class MatchFundsRemainingService
{
    /**
     * should match SF Current_Match_Funds_Available__c
     *
     * In Sf this is calcualted using formula field `Current_Match_Funds_Available__c`, see
     * https://github.com/thebiggive/salesforce/blob/2bf1ddcdeb96110003f694ecb688da0e10db85d6/force-app/main/default/objects/CCampaign__c/fields/Current_Match_Funds_Available__c.field-meta.xml
     *
     * That logic will have to be replicated here.
     *
     * For hybrid model campaigns and IMF or Regular Giving campaigns we will need the Total_Funding_Allocation__c from
     * the related meta/master campaign.
     *
     * As we don't yet have that in the Matchbot the only case we can calculate a match funds remaining at this stage
     *would be the Neither a Hybrid model nor a normal emergency IMF campaign where the formula is
     * `Total_Matched_Funds_Available__c - Matched_Confirmed_Amount__c - Total_Matched_Champion_Funds_Preauth__c`
     *
     * But we can't really get even that without having the meta campaign because we need to look at the meta campaign
     * to see whether it `Is_Emergency_IMF__c`. We might be able to get away without having master campaigns in matchbot
     * db by using `parentUsesSharedFunds` but I think that's a normalisation that probably makes things more
     * confusing and I'd rather avoid.
     *
     * So it looks like we're not ready to create an implementation better than the below just yet.
     */
    public function getFundsRemaining(CampaignDomainModel $campaign): float
    {
        $matchFundsRemaining = $campaign->getSalesforceData()['matchFundsRemaining'];

        \assert(\is_float($matchFundsRemaining));

        return $matchFundsRemaining;
    }
}
