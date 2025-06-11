<?php

namespace MatchBot\Domain;

use MatchBot\Application\Assertion;
use MatchBot\Domain\Campaign as CampaignDomainModel;

class MatchFundsService
{
    public function __construct(private CampaignFundingRepository $campaignFundingRepository,)
    {
    }
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
     * db by using `parentUsesSharedFunds` but I think that's a denormalisation that probably makes things more
     * confusing and I'd rather avoid.
     *
     * So it looks like we're not ready to create an implementation better than the below just yet.
     */
    public function getFundsRemaining(CampaignDomainModel $campaign): Money
    {
        $currencyCode = $campaign->getCurrencyCode();
        Assertion::notNull($currencyCode, 'cannot get funds remaining for campaign with null currency');

        $funds = $this->campaignFundingRepository->getAvailableFundings($campaign);

        // todo - consider optimising by doing the summation in the DB. But I think the number of funds will be low
        // enough in all or nearly all cases that the performance where will be OK.

        $runningTotal = '0.00';
        foreach ($funds as $fund) {
            $amount = $fund->getAmountAvailable();
            Assertion::same($currencyCode, $fund->getCurrencyCode(), 'fund currency code must equal campaign currency code');
            $runningTotal = \bcadd($runningTotal, $amount, 2);
        }

        return Money::fromNumericString($runningTotal, Currency::fromIsoCode($currencyCode));
    }

    public function getTotalFunds(CampaignDomainModel $campaign): Money
    {
        $currencyCode = $campaign->getCurrencyCode();
        Assertion::notNull($currencyCode, 'cannot get fundings remaining for campaign with null currency');

        $fundings = $this->campaignFundingRepository->getAllFundingsForCampaign($campaign);

        // todo - consider optimising by doing the summation in the DB. But I think the number of fundings will be low
        // enough in all or nearly all cases that the performance where will be OK.

        $runningTotal = '0.00';
        foreach ($fundings as $funding) {
            $amount = $funding->getAmount();
            Assertion::same($currencyCode, $funding->getCurrencyCode(), 'funding currency code must equal campaign currency code');
            $runningTotal = \bcadd($runningTotal, $amount, 2);
        }

        return Money::fromNumericString($runningTotal, Currency::fromIsoCode($currencyCode));
    }

    public function getFundsRemainingForMetaCampaign(MetaCampaign $metaCampaign): Money
    {
        $currencyCode = $metaCampaign->getCurrency()->isoCode();

        $funds = $this->campaignFundingRepository->getAvailableFundingsForMetaCampaign($metaCampaign);

        // todo - consider optimising by doing the summation in the DB. For the equivilent function for an individual
        // charity campaign the performance may not be too bad as there will be not so may funds. For this it
        // will probably be quite a bit worse so will likely need optimising.

        $runningTotal = '0.00';
        foreach ($funds as $fund) {
            $amount = $fund->getAmountAvailable();
            Assertion::same($currencyCode, $fund->getCurrencyCode(), 'fund currency code must equal campaign currency code');
            $runningTotal = \bcadd($runningTotal, $amount, 2);
        }

        return Money::fromNumericString($runningTotal, Currency::fromIsoCode($currencyCode));
    }
}
