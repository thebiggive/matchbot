<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use MatchBot\Client;

class FundRepository extends SalesforceReadProxyRepository
{
    /** @var CampaignFundingRepository */
    private $campaignFundingRepository;

    public function setCampaignFundingRepository(CampaignFundingRepository $repository): void
    {
        $this->campaignFundingRepository = $repository;
    }

    /**
     * @param Campaign  $campaign
     * @throws Client\NotFoundException if Campaign not found on Salesforce
     */
    public function pullForCampaign(Campaign $campaign): void
    {
        $client = $this->getClient();
        $fundsData = $client->getForCampaign($campaign->getSalesforceId());
        foreach ($fundsData as $fundData) {
            // For each fund linked to the campaign, look it up or create it if unknown
            $fund = $this->findOneBy(['salesforceId' => $fundData['id']]);
            if (!$fund) {
                if ($fundData['type'] === 'pledge') {
                    $fund = new Pledge();
                } elseif ($fundData['type'] === 'championFund') {
                    $fund = new ChampionFund();
                } else {
                    throw new \UnexpectedValueException("Unknown fund type '{$fundData['type']}'");
                }
                $fund->setSalesforceId($fundData['id']);
            }

            // Then whether new or existing, set its key info
            $fund->setAmount($fundData['totalAmount'] === null ? '0.00' : (string) $fundData['totalAmount']);
            $fund->setName($fundData['name']);
            $fund->setSalesforceLastPull(new DateTime('now'));
            $this->getEntityManager()->persist($fund);
            $this->getEntityManager()->flush(); // Need the fund ID for the CampaignFunding find

            // If there's already a CampaignFunding for this campaign+fund combination, use that
            $campaignFunding = $this->campaignFundingRepository->getFunding($campaign, $fund);
            // Otherwise create one
            if (!$campaignFunding) {
                $campaignFunding = new CampaignFunding();
                $campaignFunding->setFund($fund);
                $campaignFunding->addCampaign($campaign);
                if ($fund instanceof Pledge) {
                    $campaignFunding->setAllocationOrder(100);
                } else {
                    $campaignFunding->setAllocationOrder(200);
                }

                // It's crucial we don't try to 'update' the `amountAvailable` after MatchBot has created the entity,
                // i.e. only call this when the entity is new. We also assume the amount for each fund is immutable
                // and so only call `setAmount()` here too.
                $amountForCampaign = $fundData['amountForCampaign'] === null
                    ? '0.00'
                    : (string) $fundData['amountForCampaign'];
                $campaignFunding->setAmountAvailable($amountForCampaign);
                $campaignFunding->setAmount($amountForCampaign);
            }

            $this->getEntityManager()->persist($campaignFunding);
        }
    }

    /**
     * Get live data for the object (which might be empty apart from the Salesforce ID) and return a full object.
     * No need to `setSalesforceLastPull()`, or EM `persist()` - just populate the fields specific to the object.
     *
     * @param Fund $fund
     * @return SalesforceReadProxy
     */
    protected function doPull(SalesforceReadProxy $fund): SalesforceReadProxy
    {
        $fundData = $this->getClient()->getById($fund->getSalesforceId());

        $fund->setAmount($fundData['totalAmount']);
        $fund->setName($fundData['name']);

        return $fund;
    }
}
