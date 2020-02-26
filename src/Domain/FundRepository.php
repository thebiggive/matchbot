<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MatchBot\Client;

class FundRepository extends SalesforceReadProxyRepository
{
    private CampaignFundingRepository $campaignFundingRepository;

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
            /** @var Fund $fund */
            $fund = $this->findOneBy(['salesforceId' => $fundData['id']]);
            if (!$fund) {
                $fund = $this->getNewFund($fundData);
            }

            // Then whether new or existing, set its key info
            $this->setAnyFundData($fund, $fundData);

            try {
                $this->getEntityManager()->persist($fund);
                $this->getEntityManager()->flush(); // Need the fund ID for the CampaignFunding find
            } catch (UniqueConstraintViolationException $exception) {
                // Somebody else made the fund with this SF ID during the previous operations.
                $this->logError('Skipping fund create as unique constraint failed on SF ID ' . $fundData['id']);

                $fund = $this->findOneBy(['salesforceId' => $fundData['id']]);
                $fund = $this->setAnyFundData($fund, $fundData);
                $this->getEntityManager()->persist($fund);
            }

            // If there's already a CampaignFunding for this campaign+fund combination, use that
            $campaignFunding = $this->campaignFundingRepository->getFunding($campaign, $fund);
            // Otherwise create one
            if (!$campaignFunding) {
                $availableFunds = getAvailableFundings($campaign);
                // Only create and link the campaignFunding for the given campaign if it has not been created
                if (!in_array($fund, $availableFunds)) {
                    $campaignFunding = new CampaignFunding();
                    $campaignFunding->setFund($fund);
                    $campaignFunding->addCampaign($campaign);
                    if ($fund instanceof Pledge) {
                        $campaignFunding->setAllocationOrder(100);
                    } else {
                        $campaignFunding->setAllocationOrder(200);
                    }
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

            try {
                $this->getEntityManager()->persist($campaignFunding);
                $this->getEntityManager()->flush();
            } catch (UniqueConstraintViolationException $exception) {
                // Somebody else created the specific funding -> proceed without modifying it.
                $this->logError(
                    'Skipping campaign funding create as constraint failed with campaign ' .
                    $campaign->getId() . ', fund ' . $fund->getId()
                );
            }
        }
    }

    protected function setAnyFundData(Fund $fund, array $fundData): Fund
    {
        $fund->setAmount($fundData['totalAmount'] === null ? '0.00' : (string) $fundData['totalAmount']);
        $fund->setName($fundData['name']);
        $fund->setSalesforceLastPull(new DateTime('now'));

        return $fund;
    }

    protected function getNewFund(array $fundData): Fund
    {
        if ($fundData['type'] === 'pledge') {
            $fund = new Pledge();
        } elseif ($fundData['type'] === 'championFund') {
            $fund = new ChampionFund();
        } else {
            throw new \UnexpectedValueException("Unknown fund type '{$fundData['type']}'");
        }
        $fund->setSalesforceId($fundData['id']);

        return $fund;
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
