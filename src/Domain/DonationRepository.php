<?php

declare(strict_types=1);

namespace MatchBot\Domain;

class DonationRepository extends SalesforceProxyReadWriteRepository
{
    /**
     * @param Donation $proxy
     * @return bool|void
     */
    public function doPush(SalesforceProxyReadWrite $proxy): bool
    {
        // TODO push with Salesforce API client
    }

    /**
     * @param Donation $proxy
     * @return Donation
     */
    public function doPull(SalesforceProxy $proxy): SalesforceProxy
    {
        // TODO pull with Salesforce API client
    }

    /**
     * TODO get CampaignFunding with a pessimistic write lock.
     * @link https://stackoverflow.com/questions/12971249/doctrine2-orm-select-for-update/17721736
     * @link https://www.doctrine-project.org/projects/doctrine-orm/en/2.6/reference/transactions-and-concurrency.html#locking-support
     */
    public function allocateMatchFunds(Donation $donation)
    {
//        $campaign = $donation->getCampaign();
    }
}
