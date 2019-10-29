<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use MatchBot\Client;

class CampaignRepository extends SalesforceReadProxyRepository
{
    /**
     * @param Campaign $campaign
     * @return Campaign
     * @throws Client\NotFoundException if Campaign not found on Salesforce
     * @throws \Exception if start or end dates' formats are invalid
     */
    protected function doPull(SalesforceReadProxy $campaign): SalesforceReadProxy
    {
        $client = $this->getClient();
        $campaignData = $client->getById($campaign->getSalesforceId());

        $charity = $this->getEntityManager()
            ->getRepository(Charity::class)
            ->findOneBy(['salesforceId' => $campaignData['charity']['id']]);
        if (!$charity) {
            $charity = new Charity();
            $charity->setSalesforceId($campaignData['charity']['id']);
        }
        $charity->setName($campaignData['charity']['name']);
        $charity->setSalesforceLastPull(new DateTime('now'));
        // We don't need to persist the Campaign, but because this is a bit side-effect-y we do need to handle the
        // Charity here for now since the base `pull()` doesn't know about this object.
        $this->getEntityManager()->persist($charity);

        $campaign->setCharity($charity);
        $campaign->setEndDate(new DateTime($campaignData['endDate']));
        $campaign->setIsMatched($campaignData['isMatched']);
        $campaign->setName($campaignData['title']);
        $campaign->setStartDate(new DateTime($campaignData['startDate']));

        return $campaign;
    }

    /**
     * @return Client\Campaign
     */
    protected function getClient(): Client\Common
    {
        if (!$this->client) {
            $this->client = new Client\Campaign();
        }

        return $this->client;
    }
}
