<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use MatchBot\Client;

class CampaignRepository extends SalesforceReadProxyRepository
{
    private FundRepository $fundRepository;

    public function setFundRepository(FundRepository $repository): void
    {
        $this->fundRepository = $repository;
    }

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

        $charity = $this->pullCharity(
            $campaignData['charity']['id'],
            $campaignData['charity']['name'],
            $campaignData['charity']['donateLinkId']
        );

        $campaign->setCharity($charity);
        $campaign->setEndDate(new DateTime($campaignData['endDate']));
        $campaign->setIsMatched($campaignData['isMatched']);
        $campaign->setName($campaignData['title']);
        $campaign->setStartDate(new DateTime($campaignData['startDate']));

        return $campaign;
    }

    /**
     * Upsert a Charity based on ID & name, persist and return it.
     * @param string $salesforceCharityId
     * @param string $charityName
     * @param string $donateLinkId
     * @return Charity
     * @throws \Doctrine\ORM\ORMException on failed persist()
     */
    private function pullCharity(
        string $salesforceCharityId,
        string $charityName,
        string $donateLinkId
    ): Charity {
        $charity = $this->getEntityManager()
            ->getRepository(Charity::class)
            ->findOneBy(['salesforceId' => $salesforceCharityId]);
        if (!$charity) {
            $charity = new Charity();
            $charity->setSalesforceId($salesforceCharityId);
        }
        $charity->setDonateLinkId($donateLinkId);
        $charity->setName($charityName);
        $charity->setSalesforceLastPull(new DateTime('now'));
        $this->getEntityManager()->persist($charity);

        return $charity;
    }
}
