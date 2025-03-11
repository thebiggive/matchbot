<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use MatchBot\Application\Assertion;
use MatchBot\Client;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\DomainException\DomainCurrencyMustNotChangeException;

/**
 * @psalm-import-type SFCampaignApiResponse from Client\Campaign
 * @template-extends SalesforceReadProxyRepository<Campaign, Client\Campaign>
 *
 * @psalm-suppress MissingConstructor - setters must be called after repo is constructed by Doctrine
 */
class CampaignRepository extends SalesforceReadProxyRepository
{
    private FundRepository $fundRepository;

    /**
     * Gets campaigns that it is particular important matchbot has up-to-date information about.
     *
     * More specifically gets those campaigns which are live now or recently closed (in the last week),
     * based on their last known end time, and those closed semi-recently where we are
     * awaiting HMRC agent approval for Gift Aid claims. This allows for campaigns to receive updates
     * shortly after closure if a decision is made to reopen them soon after the end date,
     * while keeping the number of API calls for regular update runs under control long-term.
     * Technically future campaigns are also included if they are already known to MatchBot,
     * though this would typically only happen after manual API call antics or if a start
     * date was pushed back belatedly after a campaign already started.
     *
     * @return Campaign[]
     */
    public function findCampaignsThatNeedToBeUpToDate(): array
    {
        $query = $this->getEntityManager()->createQuery(<<<DQL
            SELECT c FROM MatchBot\Domain\Campaign c
            INNER JOIN c.charity charity
            WHERE c.endDate >= :shortLookBackDate OR (
                charity.tbgClaimingGiftAid = 1 AND
                charity.tbgApprovedToClaimGiftAid = 0 AND
                c.endDate >= :extendedLookbackDate
            )
            ORDER BY c.createdAt ASC
            DQL
        );
        $query->setParameters([
            'shortLookBackDate' => (new DateTime('now'))->sub(new \DateInterval('P7D')),
            'extendedLookbackDate' => (new DateTime('now'))->sub(new \DateInterval('P9M')),
        ]);

        /** @var Campaign[] $campaigns */
        $campaigns = $query->getResult();

        return $campaigns;
    }

    /** @return list<Campaign> */
    public function findUpdatableForCharity(Salesforce18Id $charitySfId): array
    {
        $query = $this->getEntityManager()->createQuery(
            <<<'DQL'
            SELECT campaign FROM MatchBot\Domain\Campaign campaign
            JOIN campaign.charity charity
            WHERE 
             charity.salesforceId = :charityId AND 
            campaign.endDate >= :eighteenMonthsAgo
            DQL
        );

        $query->setParameters([
            'charityId' => $charitySfId->value,
            'eighteenMonthsAgo' => (new \DateTime('now'))->sub(new \DateInterval('P18M')),
        ]);

        /** @var list<Campaign> $result */
        $result =  $query->getResult();

        return $result;
    }

    /**
     * @param Salesforce18Id<Campaign> $salesforceId
     * @throws NotFoundException
     */
    public function pullNewFromSf(Salesforce18Id $salesforceId): Campaign
    {
        $campaignData = $this->getClient()->getById($salesforceId->value, true);

        $charity = $this->pullCharity($campaignData);

        $regularGivingCollectionEnd = $campaignData['regularGivingCollectionEnd'] ?? null;
        $regularGivingCollectionObject = $regularGivingCollectionEnd === null ?
            null : new \DateTimeImmutable($regularGivingCollectionEnd);

        $campaign = new Campaign(
            sfId: $salesforceId,
            charity: $charity,
            startDate: new \DateTimeImmutable($campaignData['startDate']),
            endDate: new \DateTimeImmutable($campaignData['endDate']),
            isMatched: $campaignData['isMatched'],
            ready: $campaignData['ready'],
            status: $campaignData['status'],
            name: $campaignData['title'],
            currencyCode: $campaignData['currencyCode'],
            isRegularGiving: $campaignData['isRegularGiving'] ?? false,
            regularGivingCollectionEnd: $regularGivingCollectionObject,
        );
        $campaign->setSalesforceLastPull(new \DateTime());

        $this->getEntityManager()->persist($campaign);
        $this->getEntityManager()->flush();

        $this->logInfo('Done persisting new campiagn ' . $campaign->getSalesforceId());

        return $campaign;
    }

    /**
     * @param Salesforce18Id<Campaign> $salesforceId
     */
    public function findOneBySalesforceId(Salesforce18Id $salesforceId): ?Campaign
    {
        return $this->findOneBy(['salesforceId' => $salesforceId->value]);
    }

    /**
     * @param string $metaCampaginSlug
     * @return array{newFetchCount: int, updatedCount: int, campaigns: list<Campaign>}
 *@throws NotFoundException
     *
     */
    public function fetchAllForMetaCampaign(string $metaCampaginSlug): array
    {
        /** @var list<array{id: string}> $campaignList */
        $campaignList = $this->client->findCampaignsForMetaCampaign($metaCampaginSlug, limit: 10_000);

        $campaignIds = array_map(function (array $campaign) {
            return Salesforce18Id::ofCampaign($campaign['id']);
        }, $campaignList);

        $newFetchCount = 0;
        $updatedCount = 0;

        $count = count($campaignIds);

        $i = 0;
        $campaigns = [];
        foreach ($campaignIds as $id) {
            $i++;

            $campaign = $this->findOneBySalesforceId($id);

            if ($campaign) {
                $this->updateFromSf($campaign, withCache: false, autoSave: true);
                $updatedCount++;
            } else {
                $campaign = $this->pullNewFromSf($id);
                $newFetchCount++;
            }
            $this->logger->info("Fetched campaign $i of $count, '{$campaign->getCampaignName()}'\n");

            $campaigns[] = $campaign;
        }

        return compact('newFetchCount', 'updatedCount', 'campaigns');
    }

    /**
     * @param  SFCampaignApiResponse $campaignData
     */
    public function pullCharity(array $campaignData): Charity
    {
        $charity = $this->getEntityManager()
            ->getRepository(Charity::class)
            ->findOneBy(['salesforceId' => $campaignData['charity']['id']]);
        if (!$charity) {
            $charity = $this->newCharityFromCampaignData($campaignData);
        } else {
            $this->updateCharityFromCampaignData($charity, $campaignData);
        }

        $this->getEntityManager()->persist($charity);

        return $charity;
    }

    /**
     * @param SFCampaignApiResponse $campaignData
     */
    public function newCharityFromCampaignData(array $campaignData): Charity
    {
        $charityData = $campaignData['charity'];

        return new Charity(
            salesforceId: $charityData['id'],
            charityName: $charityData['name'],
            websiteUri: $charityData['website'],
            logoUri: $charityData['logoUri'],
            stripeAccountId: $charityData['stripeAccountId'],
            hmrcReferenceNumber: $charityData['hmrcReferenceNumber'],
            giftAidOnboardingStatus: $charityData['giftAidOnboardingStatus'],
            regulator: $this->getRegulatorHMRCIdentifier($charityData['regulatorRegion']),
            regulatorNumber: $charityData['regulatorNumber'],
            time: new \DateTime('now'),
            rawData: $charityData,
        );
    }

    /**
     * @param SFCampaignApiResponse $campaignData
     */
    public function updateCharityFromCampaignData(Charity $charity, array $campaignData): void
    {
        $charityData = $campaignData['charity'];

        $charity->updateFromSfPull(
            charityName: $charityData['name'],
            websiteUri: $charityData['website'],
            logoUri: $charityData['logoUri'],
            stripeAccountId: $charityData['stripeAccountId'],
            hmrcReferenceNumber: $charityData['hmrcReferenceNumber'],
            giftAidOnboardingStatus: $charityData['giftAidOnboardingStatus'],
            regulator: $this->getRegulatorHMRCIdentifier($charityData['regulatorRegion']),
            regulatorNumber: $charityData['regulatorNumber'],
            rawData: $charityData,
            time: new \DateTime('now'),
        );
    }

    /**
     * Checks if the given campaign is already in the DB - if so does nothing, if not pulls it from Salesforce.
     *
     * For performance only does a count, doesn't load the campaign from the DB if it already exists.
     *
     * @param Salesforce18Id<Campaign> $campaignId
     */
    public function pullFromSFIfNotPresent(Salesforce18Id $campaignId): void
    {
        $query = $this->getEntityManager()->createQuery(
            'SELECT count(c.id) from MatchBot\Domain\Campaign c where c.salesforceId = :id'
        );
        $query->setParameter('id', $campaignId->value);
        $count = $query->getSingleScalarResult();

        if ($count === 0) {
            $campaign = $this->pullNewFromSf($campaignId);
            $this->fundRepository->pullForCampaign($campaign);
        }
    }

    public function setFundRepository(FundRepository $fundRepository): void
    {
        $this->fundRepository = $fundRepository;
    }

    /**
     * @throws Client\NotFoundException if Campaign not found on Salesforce
     * @throws \Exception if start or end dates' formats are invalid
     */
    protected function doUpdateFromSf(SalesforceReadProxy $proxy, bool $withCache): void
    {
        $campaign = $proxy;

        /** @psalm-suppress RedundantConditionGivenDocblockType - redundant for Psalm but useful for PHPStorm */
        \assert($campaign instanceof Campaign);

        $client = $this->getClient();
        $campaignData = $client->getById($campaign->getSalesforceId(), $withCache);

        $this->updateCharityFromCampaignData($proxy->getCharity(), $campaignData);

        if ($campaign->hasBeenPersisted() && $campaign->getCurrencyCode() !== $campaignData['currencyCode']) {
            $this->logWarning(sprintf(
                'Refusing to update campaign currency to %s for SF ID %s',
                $campaignData['currencyCode'],
                $campaignData['id'],
            ));

            throw new DomainCurrencyMustNotChangeException();
        }

        $feePercentage = $campaignData['feePercentage'] ?? null;
        Assertion::null($feePercentage, "Fee percentages are no-longer supported, should always be null");

        if ($campaignData['status'] === null) {
            $this->logger->debug("null status from SF for campaign " . $campaignData['id']);
        }

        $regularGivingCollectionEnd = $campaignData['regularGivingCollectionEnd'] ?? null;
        $regularGivingCollectionObject = $regularGivingCollectionEnd === null ?
            null : new \DateTimeImmutable($regularGivingCollectionEnd);


        $campaign->updateFromSfPull(
            currencyCode: $campaignData['currencyCode'] ?? 'GBP',
            status: $campaignData['status'],
            endDate: new DateTime($campaignData['endDate']),
            isMatched: $campaignData['isMatched'],
            name: $campaignData['title'],
            startDate: new DateTime($campaignData['startDate']),
            ready: $campaignData['ready'],
            isRegularGiving: $campaignData['isRegularGiving'] ?? false,
            regularGivingCollectionEnd: $regularGivingCollectionObject,
            thankYouMessage: $campaignData['thankYouMessage'],
            sfData: $campaignData,
        );
        $this->getEntityManager()->flush();
    }

    protected function getRegulatorHMRCIdentifier(string $regulatorName): ?string
    {
        return match ($regulatorName) {
            'England and Wales' => 'CCEW',
            'Northern Ireland' => 'CCNI',
            'Scotland' => 'OSCR',
            default => null,
        };
    }
}
