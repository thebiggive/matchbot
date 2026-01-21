<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\ORM\QueryBuilder;
use GuzzleHttp\Exception\ClientException;
use MatchBot\Application\Assertion;
use MatchBot\Application\AssertionFailedException;
use MatchBot\Client;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\DomainException\DomainCurrencyMustNotChangeException;
use Symfony\Component\Clock\ClockInterface;

use function is_string;
use function trim;

/**
 * @psalm-import-type SFCampaignApiResponse from Client\Campaign
 * @template-extends SalesforceReadProxyRepository<Campaign, Client\Campaign>
 *
 * @psalm-suppress MissingConstructor - setters must be called after repo is constructed by Doctrine
 */
class CampaignRepository extends SalesforceReadProxyRepository
{
    private FundRepository $fundRepository; // @phpstan-ignore property.uninitialized

    private ClockInterface $clock;  // @phpstan-ignore property.uninitialized

    /**
     * Gets campaigns that it is particular important matchbot has up-to-date information about.
     *
     * More specifically gets those campaigns which are live now or recently closed (in the last week),
     * based on their last known end time, and those closed semi-recently where we are
     * awaiting HMRC agent approval for Gift Aid claims, and regular giving campaigns.
     * This allows for campaigns to receive updates shortly after closure if a decision is made to reopen them soon after the end date,
     * while keeping the number of API calls for regular update runs under control long-term.
     * Technically future campaigns are also included if they are already known to MatchBot,
     * though this would typically only happen after manual API call antics or if a start
     * date was pushed back belatedly after a campaign already started.
     *
     * Regular giving campaigns are all included as they may have ongoing donations after the end date - changes
     * in particular to the regularGivingCollectionEnd date in any direction need to be pulled quickly into matchbot
     * to control whether we do or don't continue to collect those donations.
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
            OR c.isRegularGiving = 1
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

    /**
     * @param Salesforce18Id<Charity> $charitySfId
     * @return list<Campaign>
     */
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
     * @throws ClientException
     */
    public function pullNewFromSf(Salesforce18Id $salesforceId): Campaign
    {
        $campaignData = $this->getClient()->getById($salesforceId->value, true);

        $charity = $this->pullCharity($campaignData);

        $campaign = Campaign::fromSfCampaignData($campaignData, $salesforceId, $charity);

        $campaign->setSalesforceLastPull(new \DateTime());

        $this->getEntityManager()->persist($campaign);
        $this->getEntityManager()->flush();

        $this->logInfo('Done persisting new campiagn ' . $campaign->getSalesforceId());

        return $campaign;
    }

    /**
     * @param Salesforce18Id<Campaign> $salesforceId
     */
    public function findOneBySalesforceId(Salesforce18Id $salesforceId, \DateTimeImmutable $mustBeUpdatedSince = null): ?Campaign
    {
        $sfIdString = $salesforceId->value;

        if (\str_starts_with(\strtolower($sfIdString), 'xxx')) {
            // SF ID was intentionally mangled for MAT-405 testing to simulate SF being down but
            // the matchbot DB being up. We know that all our campaign IDs start with a05 so we fix it to query our DB.
            $sfIdString = 'a05' . \substr($sfIdString, 3);
        }

        $campaign = $this->findOneBy(['salesforceId' => $sfIdString]);

        $campaignUpdatedAt = $campaign?->getSalesforceLastPull();

        if ($mustBeUpdatedSince && $campaignUpdatedAt < $mustBeUpdatedSince) {
            $this->logWarning(
                "Not returning stale campaign {$sfIdString}, last updated {$campaignUpdatedAt?->format('c')}, should have been since {$mustBeUpdatedSince->format('c')}"
            );

            return null;
        }

        return $campaign;
    }

    /**
     * @return array{newFetchCount: int, updatedCount: int, campaigns: list<Campaign>}
 *@throws NotFoundException
     *
     */
    public function fetchAllForMetaCampaign(MetaCampaignSlug $metaCampaginSlug): array
    {
        /** @var list<array{id: string}> $campaignList */
        $campaignList = $this->getClient()->findCampaignsForMetaCampaign($metaCampaginSlug, limit: 10_000);

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
            $this->getLogger()->info("Fetched campaign $i of $count, '{$campaign->getCampaignName()}'\n");

            $campaigns[] = $campaign;
        }

        return compact('newFetchCount', 'updatedCount', 'campaigns');
    }

    /**
     * @param  SFCampaignApiResponse $campaignData
     * @throws AssertionFailedException if data in SF not fit in our charity model.
     */
    public function pullCharity(array $campaignData): Charity
    {
        $charityData = $campaignData['charity'];
        Assertion::notNull($charityData);

        $id = $charityData['id'];

        // getting some unexplained duplicate stripe ID errors, this may help explain?
        $this->getLogger()->info("Pulling charity id: $id");
        $this->getLogger()->info("Charity Data from SF: " . json_encode($charityData, flags: \JSON_THROW_ON_ERROR));

        $charity = $this->getEntityManager()
            ->getRepository(Charity::class)
            ->findOneBy(['salesforceId' => $id]);
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
        Assertion::notNull($charityData, 'Charity must not be null for charity campaign');

        $emailString = $charityData['emailAddress'] ?? null;
        $emailAddress = is_string($emailString) && trim($emailString) !== '' ? EmailAddress::of($emailString) : null;

        return new Charity(
            salesforceId: $charityData['id'],
            charityName: $charityData['name'],
            stripeAccountId: $charityData['stripeAccountId'],
            hmrcReferenceNumber: $charityData['hmrcReferenceNumber'],
            giftAidOnboardingStatus: $charityData['giftAidOnboardingStatus'],
            regulator: self::getRegulatorHMRCIdentifier($charityData['regulatorRegion']),
            regulatorNumber: $charityData['regulatorNumber'],
            time: new \DateTime('now'),
            rawData: $charityData,
            websiteUri: $charityData['website'],
            logoUri: $charityData['logoUri'],
            phoneNumber: $charityData['phoneNumber'] ?? null,
            emailAddress: $emailAddress,
        );
    }

    /**
     * @param SFCampaignApiResponse $campaignData
     * @throws AssertionFailedException if data given does not fit in our charity model.
     */
    public function updateCharityFromCampaignData(Charity $charity, array $campaignData): void
    {
        $charityData = $campaignData['charity'];
        Assertion::notNull($charityData, 'Charity date should not be null for charity campaign');

        $emailString = $charityData['emailAddress'] ?? null;
        $emailAddress = is_string($emailString) && trim($emailString) !== '' ? EmailAddress::of($emailString) : null;

        $charity->updateFromSfPull(
            charityName: $charityData['name'],
            websiteUri: $charityData['website'],
            logoUri: $charityData['logoUri'],
            stripeAccountId: $charityData['stripeAccountId'],
            hmrcReferenceNumber: $charityData['hmrcReferenceNumber'],
            giftAidOnboardingStatus: $charityData['giftAidOnboardingStatus'],
            regulator: self::getRegulatorHMRCIdentifier($charityData['regulatorRegion']),
            regulatorNumber: $charityData['regulatorNumber'],
            rawData: $charityData,
            time: new \DateTime('now'),
            phoneNumber: $charityData['phoneNumber'] ?? null,
            emailAddress: $emailAddress,
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
            $this->fundRepository->pullForCampaign($campaign, $this->clock->now());
        }
    }

    public function setFundRepository(FundRepository $fundRepository): void
    {
        $this->fundRepository = $fundRepository;
    }

    /**
     * Returns the total of all the complete donations to this campaign, excluding matching and Gift Aid.
     */
    public function totalCoreDonations(Campaign $campaign): Money
    {
        $donationQuery = $this->getEntityManager()->createQuery(
            <<<'DQL'
            SELECT donation.currencyCode, COALESCE(SUM(donation.amount), 0) as sum
            FROM MatchBot\Domain\Donation donation
            WHERE donation.campaign = :campaignId AND donation.donationStatus IN (:succcessStatus)
            GROUP BY donation.currencyCode
        DQL
        );

        $donationQuery->setParameters([
            'campaignId' => $campaign->getId(),
            'succcessStatus' => DonationStatus::SUCCESS_STATUSES,
        ]);

        /** @var list<array{currencyCode: string, sum: numeric-string}> $donationResult */
        $donationResult =  $donationQuery->getResult();

        if ($donationResult === []) {
            return Money::zero($campaign->getCurrency());
        }

        Assertion::count(
            $donationResult,
            1,
            "multiple currency donations found for same campaign, can't calculate sum"
        );

        $donationSumNumeric = $donationResult[0]['sum'];

        return Money::fromNumericString($donationSumNumeric, Currency::fromIsoCode($donationResult[0]['currencyCode']));
    }

    public function totalMatchFundsUsed(int $campaignId): Money
    {
        // FW doesn't use Money value object or have its own currency code field yet, so we join to CampaignFunding
        // solely to get that.
        $query = $this->getEntityManager()->createQuery(
            <<<'DQL'
            SELECT COALESCE(SUM(fw.amount), 0) as sum, cf.currencyCode as currencyCode
            FROM MatchBot\Domain\FundingWithdrawal fw
            JOIN fw.donation donation
            JOIN fw.campaignFunding cf
            WHERE donation.campaign = :campaignId AND donation.donationStatus IN (:succcessStatus)
            GROUP BY cf.currencyCode
        DQL
        );

        $query->setParameters([
            'campaignId' => $campaignId,
            'succcessStatus' => DonationStatus::SUCCESS_STATUSES,
        ]);

        /** @var null|array{sum: numeric-string, currencyCode: string} $result */
        $result = $query->getOneOrNullResult();

        if ($result === null) {
            return Money::zero();
        }
        Assertion::numeric($result['sum']);

        return Money::fromNumericString($result['sum'], Currency::fromIsoCode($result['currencyCode']));
    }

    /**
     * Returns a list of campaigns that should be displayed for the given Charity.
     *
     * Consider optimising by only selecting fields needed for 'campaign' summaries - maybe not so much
     * for this but for a similar function we will write to get campaigns for a meta campaign or a search
     *
     *
     * @return list<Campaign>
     */
    public function findCampaignsForCharityPage(Charity $charity): array
    {
        $query = $this->getEntityManager()->createQuery(
            <<<'DQL'
            SELECT campaign FROM MatchBot\Domain\Campaign campaign
            WHERE 
             campaign.charity = :charity
             AND campaign.status IN ('Active', 'Preview', 'Expired')
             ORDER BY campaign.status ASC, campaign.endDate ASC 
            DQL
        );

        $query->setParameters([
            'charity' => $charity,
        ]);

        /** @var list<Campaign> $result */
        $result =  $query->getResult();

        return $result;
    }

    public function countCampaignsInMetaCampaign(MetaCampaign $metaCampaign): int
    {
        // query copied from SOQL query in Salesforce function CampaignService.campaignSfToApi
        $query = $this->getEntityManager()->createQuery(<<<'DQL'
            SELECT COUNT(c.id)
            FROM MatchBot\Domain\Campaign c
            WHERE c.metaCampaignSlug = :slug
            AND c.status IN ('Active', 'Preview', 'Expired')
            AND c.relatedApplicationStatus = 'Approved'
            AND c.relatedApplicationCharityResponseToOffer = 'Accepted'
        DQL
        );

        $query->setParameter('slug', $metaCampaign->getSlug()->slug);

        $count = (int)$query->getSingleScalarResult();

        \assert($count >= 0);

        return $count;
    }

    public function setClock(ClockInterface $clock): void
    {
        $this->clock = $clock;
    }

    /**
     * @return list<Campaign> Each campaign with a donation updated recently.
     * It's more DB-efficient to check for any update than for recent collection & recent refunds.
     *
     * Performance note: We rely on Donation.updatedAt reflecting any FundingWithdrawal changes.
     * FundingWithdrawal has lifecycle callbacks that call $donation->updatedNow() on persist/update.
     * This allows us to avoid extra joins here and keep this query fast for the once-per-minute poller.
     */
    public function findWithDonationChangesSince(\DateTimeImmutable $updatedAfter): array
    {
        $query = $this->getEntityManager()->createQuery(
            <<<'DQL'
            SELECT DISTINCT campaign
            FROM MatchBot\Domain\Campaign campaign
            JOIN MatchBot\Domain\Donation donation WITH donation.campaign = campaign.id
            WHERE donation.updatedAt >= :donationUpdatedAfter
            DQL
        );

        $query->setParameter('donationUpdatedAfter', $updatedAfter);

        /** @var list<Campaign> $result */
        $result =  $query->getResult();

        return $result;
    }

    /**
     * @return list<Campaign> Each campaign with no stats since $oldestExpected.
     */
    public function findCampaignsWithNoRecentStats(\DateTimeImmutable $oldestExpected): array
    {
        $query = $this->getEntityManager()->createQuery(
            <<<'DQL'
            SELECT campaign FROM MatchBot\Domain\Campaign campaign
            LEFT OUTER JOIN campaign.campaignStatistics stats
            WHERE stats.lastCheck IS NULL OR stats.lastCheck < :oldestExpected
            ORDER BY campaign.createdAt ASC
        DQL
        );
        $query->setParameter('oldestExpected', $oldestExpected);

        /** @var list<Campaign> $result */
        $result =  $query->getResult();

        return $result;
    }

    /**
     * @param Campaign $campaign
     * @param  SFCampaignApiResponse $campaignData
     * @throws AssertionFailedException if data given does not fit in our charity model.
     */
    public function updateCampaignFromSFData(Campaign $campaign, array $campaignData, bool $alsoUpdateCharity = true): void
    {
        $startDateString = $campaignData['startDate'];
        $endDateString = $campaignData['endDate'];
        $title = $campaignData['title'];

        // dates may be null for a non-launched early stage preview campaign, but not for a campaign that we're pulling
        // into the matchbot DB via an update.
        Assertion::notNull($startDateString, "Null start date supplied when attempting to update campaign {$campaign->getSalesforceId()}");
        Assertion::notNull($endDateString, "Null end date supplied when attempting to update campaign {$campaign->getSalesforceId()}");
        Assertion::notNull($title, "Null title supplied when attempting to updated campaign {$campaign->getSalesforceId()}");

        if ($alsoUpdateCharity) {
            $this->updateCharityFromCampaignData($campaign->getCharity(), $campaignData);
        }

        if ($campaign->hasBeenPersisted() && $campaign->getCurrencyCode() !== $campaignData['currencyCode']) {
            $this->logWarning(sprintf(
                'Refusing to update campaign currency to %s for SF ID %s',
                $campaignData['currencyCode'],
                $campaignData['id'],
            ));

            throw new DomainCurrencyMustNotChangeException();
        }

        if ($campaignData['status'] === null) {
            $this->getLogger()->debug("null status from SF for campaign " . $campaignData['id']);
        }

        $regularGivingCollectionEnd = $campaignData['regularGivingCollectionEnd'] ?? null;
        $regularGivingCollectionObject = $regularGivingCollectionEnd === null ?
            null : new \DateTimeImmutable($regularGivingCollectionEnd);

        $currency = Currency::fromIsoCode($campaignData['currencyCode']);

        $relatedApplicationStatusString = $campaignData['relatedApplicationStatus'] ?? null;
        $relatedApplicationCharityResponseToOfferString = $campaignData['relatedApplicationCharityResponseToOffer'] ?? null;
        $campaign->updateFromSfPull(
            currencyCode: $currency->isoCode(),
            status: $campaignData['status'],
            pinPosition: $campaignData['pinPosition'] ?? null,
            championPagePinPosition: $campaignData['championPagePinPosition'] ?? null,
            relatedApplicationStatus: is_string($relatedApplicationStatusString) ? ApplicationStatus::from($relatedApplicationStatusString) : null,
            relatedApplicationCharityResponseToOffer: is_string($relatedApplicationCharityResponseToOfferString) ? CharityResponseToOffer::from($relatedApplicationCharityResponseToOfferString) : null,
            endDate: new DateTime($endDateString),
            isMatched: $campaignData['isMatched'],
            name: $title,
            summary: $campaignData['summary'],
            metaCampaignSlug: $campaignData['parentRef'],
            startDate: new DateTime($startDateString),
            ready: $campaignData['ready'],
            isRegularGiving: $campaignData['isRegularGiving'] ?? false,
            regularGivingCollectionEnd: $regularGivingCollectionObject,
            thankYouMessage: $campaignData['thankYouMessage'],
            hidden: $campaignData['hidden'] ?? false,
            totalFundingAllocation: Money::fromPence((int)(100.0 * ($campaignData['totalFundingAllocation'] ?? 0.0)), $currency),
            amountPledged: Money::fromPence((int)(100.0 * ($campaignData['amountPledged'] ?? 0.0)), $currency),
            totalFundraisingTarget: Money::fromPence((int)(100.0 * ($campaignData['totalFundraisingTarget'] ?? 0.0)), $currency),
            sfData: $campaignData,
        );
    }

    /**
     * @param QueryBuilder $qb Builder with its select etc. already set up.
     * @param array<string, string> $jsonMatchInListConditions
     *
     * @return list<int>| null campaign IDs sorted by relavence if doing a full text search, otherwise null.
     */
    private function filterForSearch(
        QueryBuilder $qb,
        ?string $status,
        ?string $metaCampaignSlug,
        ?string $fundSlug,
        array $jsonMatchInListConditions,
        ?string $termWildcarded,
        bool $filterOutTargetMet,
        bool $fullText,
        ?string $term,
    ): array|null {
        $qb->andWhere($qb->expr()->eq('campaign.hidden', '0'));
        $qb->andWhere($qb->expr()->eq('campaign.isMatched', '1'));

        if ($status !== null) {
            $qb->andWhere($qb->expr()->eq('campaign.status', ':status'));
            $qb->setParameter('status', $status);
        } elseif ($metaCampaignSlug === null) {
            $qb->andWhere('campaign.status IN (:nonExpired)');
            // we can't rely on the status being updated when the campaign expires, so also check that the end-date is not past:
            $qb->andWhere($qb->expr()->gt('campaign.endDate', ':now'));
            $qb->setParameter('nonExpired', ['Active', 'Preview']);
            $qb->setParameter('now', $this->clock->now());
        } else {
            $qb->andWhere('campaign.status IS NOT NULL');
        }

        $qb->andWhere(<<<DQL
            campaign.metaCampaignSlug IS NULL OR
            (
                campaign.relatedApplicationStatus = 'Approved' AND
                campaign.relatedApplicationCharityResponseToOffer = 'Accepted'
            )
            DQL
        );

        if ($metaCampaignSlug !== null) {
            $qb->andWhere($qb->expr()->eq('campaign.metaCampaignSlug', ':metaCampaignSlug'));
            $qb->setParameter('metaCampaignSlug', $metaCampaignSlug);
        }

        if ($fundSlug !== null) {
            $qb->andWhere($qb->expr()->eq('fund.slug', ':fundSlug'))
                ->andWhere($qb->expr()->gt('campaignFunding.amount', 0));
            $qb->setParameter('fundSlug', $fundSlug);
        }

        foreach ($jsonMatchInListConditions as $field => $value) {
            $qb->andWhere($qb->expr()->like("JSON_EXTRACT(campaign.salesforceData, '$.$field')", ':jsonMatchInList_' . $field));
            $qb->setParameter('jsonMatchInList_' . $field, '%' . $value . '%');
        }

        if ($termWildcarded !== null && ! $fullText) {
            $whereSummaryMatches = "LOWER(JSON_EXTRACT(campaign.salesforceData, '$.summary')) LIKE LOWER(:termForWhere)";
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('charity.name', ':termForWhere'),
                    $qb->expr()->like('campaign.name', ':termForWhere'),
                    $whereSummaryMatches,
                )
            );
            $qb->setParameter('termForWhere', $termWildcarded);
        }

        $ids = null;
        if (is_string($term) && $fullText) {
            // we use the same regex in the database to define the generated columns Campaign.normalisedName and
            // Charity.normalisedName
            $termWithoutApostrophes = preg_replace("/['`‘’]+/", '', $term);

            /** @var list<int> $ids */
            $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
                'SELECT Campaign.id,
                        MATCH(Campaign.normalisedName) AGAINST(:term_normalised IN NATURAL LANGUAGE MODE) * 5 +
                        MATCH(Charity.normalisedName) AGAINST(:term_normalised IN NATURAL LANGUAGE MODE) * 3 +
                        MATCH(Campaign.searchable_text) AGAINST(:term IN NATURAL LANGUAGE MODE) * 1 +
                        MATCH(Charity.searchable_text) AGAINST(:term IN NATURAL LANGUAGE MODE) * 1 as score                       
                     FROM Campaign LEFT JOIN Charity ON Campaign.charity_id = Charity.id 
                   WHERE ((MATCH (Campaign.searchable_text) AGAINST (:term IN NATURAL LANGUAGE MODE)) OR
                         ( MATCH (Charity.searchable_text) AGAINST (:term IN NATURAL LANGUAGE MODE))
                   )
                    ORDER BY score DESC',
                [
                    'term_normalised' => $termWithoutApostrophes,
                    'term' => $term
                ]
            );

            $qb->andWhere('campaign.id IN (:ids)');
            $qb->setParameter('ids', $ids);
        }


        if ($filterOutTargetMet) {
            $qb->andWhere($qb->expr()->neq('campaignStatistics.distanceToTarget.amountInPence', 0));
        }

        Assertion::nullOrIsArray($ids);
        return $ids;
    }

    /**
     * @param list<int>|null $idsOrderedByRelavence
     * @param QueryBuilder $qb Builder with its select etc. already set up.
     * @param non-empty-string $safeSortField
     */
    private function sortForSearch(
        QueryBuilder $qb,
        bool $applyPinSort,
        string $safeSortField,
        string $sortDirection,
        ?string $termWildcarded,
        array|null $idsOrderedByRelavence
    ): void {
        // Active, Expired, Preview in that order; status sort takes highest precedence.
        $qb->addOrderBy('campaign.status', 'asc');

        if ($applyPinSort) {
            $qb->addOrderBy('pinPosition', 'asc');
        }

        if ($safeSortField === 'relevance') {
            if (\is_array($idsOrderedByRelavence)) {
                $qb->addOrderBy('FIELD(campaign.id, :orderedIds)', 'ASC');
                $qb->setParameter('orderedIds', $idsOrderedByRelavence);
            } else {
                $qb->addOrderBy(
                    <<<EOT
            CASE
                WHEN charity.name LIKE :termForOrder THEN 20
                WHEN campaign.name LIKE LOWER(:termForOrder) THEN 10
                WHEN LOWER(JSON_EXTRACT(campaign.salesforceData, '$.summary')) LIKE LOWER(:termForOrder) THEN 5
                ELSE 0
            END
            EOT,
                    $sortDirection,
                );
                $qb->setParameter('termForOrder', $termWildcarded);
            }
        } else {
            $qb->addOrderBy($safeSortField, ($sortDirection === 'asc') ? 'asc' : 'desc');
        }

        // order additionally by id to make sure we have a total order in case other fields are equal.
        // Total order is required so we don't lose items in pagination, even with the (still required)
        // assumption that the user pages through campaigns faster than the data in the db changes.

        // This means campaigns that were set up earlier will show first, which seems reasonable.
        $qb->addOrderBy('campaign.id');
    }

    /**
     * @param 'asc'|'desc' $sortDirection
     * @param array<string, string> $jsonMatchInListConditions Keyed on plural JSON key name. Value must exactly match
     *                                                         one of the items in the JSON array with the same key.
     *
     * Warning - keys in $jsonMatchInListConditionsmust be literal strings otherwise there will be SQL injection vuulnerabilities.
     *
     * @return list<Campaign>
     */
    public function search(
        string $sortField,
        string $sortDirection,
        int $offset,
        int $limit,
        ?string $status,
        ?string $metaCampaignSlug,
        ?string $fundSlug,
        array $jsonMatchInListConditions,
        ?string $term,
        bool $fulltext = false,
    ): array {
        $qb = $this->getEntityManager()->createQueryBuilder();

        $safeSortField = match ($sortField) {
            'amountRaised' => 'campaignStatistics.amountRaised.amountInPence',
            'distanceToTarget' => 'campaignStatistics.distanceToTarget.amountInPence',
            'matchFundsRemaining' => 'campaignStatistics.matchFundsRemaining.amountInPence',
            'matchFundsUsed' => 'campaignStatistics.matchFundsUsed.amountInPence',
            'relevance' => 'relevance',
            default => throw new \InvalidArgumentException("Invalid sort field '$sortField'"),
        };

        if ($term === null && $safeSortField === 'relevance') {
            throw new \InvalidArgumentException('Please provide a term to sort by relevance');
        }

        $qb->select(<<<SELECT
              campaign,
              COALESCE(campaign.pinPosition, 999999999) AS HIDDEN pinPosition
            SELECT)
            ->from(Campaign::class, 'campaign')
            ->join('campaign.charity', 'charity')
            // Campaigns must have a stats record to be searched. Probably OK and worth it to keep the
            // join & sorting simple.
            ->join('campaign.campaignStatistics', 'campaignStatistics')
            ->leftJoin('campaign.campaignFundings', 'campaignFunding')
            ->leftJoin('campaignFunding.fund', 'fund')
            ->groupBy('campaign.id')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        if ($term !== null) {
            // I think because binding takes care of non-LIKE escapes we only need to consider % and _.
            $termWildcarded = '%' . addcslashes($term, '%_') . '%';
        } else {
            $termWildcarded = null;
        }

        $filterOutTargetMet =
            $safeSortField === 'campaignStatistics.distanceToTarget.amountInPence' &&
            $sortDirection === 'asc';

        $idsOrderedByRelavence = $this->filterForSearch(
            qb: $qb,
            status: $status,
            metaCampaignSlug: $metaCampaignSlug,
            fundSlug: $fundSlug,
            jsonMatchInListConditions: $jsonMatchInListConditions,
            termWildcarded: $termWildcarded,
            filterOutTargetMet: $filterOutTargetMet,
            fullText: $fulltext,
            term: $term,
        );

        $this->sortForSearch(
            qb: $qb,
            applyPinSort: $jsonMatchInListConditions === [],
            safeSortField: $safeSortField,
            sortDirection: $sortDirection,
            termWildcarded: $termWildcarded,
            idsOrderedByRelavence: $idsOrderedByRelavence,
        );

        $query = $qb->getQuery();

        /** @var list<Campaign> $result */
        $result = $query->getResult();

        return $result;
    }

    public static function getRegulatorHMRCIdentifier(string $regulatorName): ?string
    {
        return match ($regulatorName) {
            'England and Wales' => 'CCEW',
            'Northern Ireland' => 'CCNI',
            'Scotland' => 'OSCR',
            default => null,
        };
    }

    /**
     * @throws Client\NotFoundException
     * @throws AssertionFailedException if data in SF does not fit in our campaign or charity model.
     */
    public function updateFromSf(
        Campaign $campaign,
        bool $withCache = true,
        bool $autoSave = true,
    ): void {
        // Make sure we update existing object if passed in a partial copy and we already have that Salesforce object
        // persisted, otherwise we'll try to insert a duplicate and get an ORM crash.
        $salesforceId = $campaign->getSalesforceId();

        if (
            $campaign->hasBeenPersisted() &&
            ($existingCampaign = $this->findOneBy(['salesforceId' => $salesforceId]))
        ) {
            $this->logInfo('Updating campaign ' . $salesforceId . '...');
            $campaign = $existingCampaign;
        } else {
            $this->logInfo('Creating campaign ' . $salesforceId);
        }

        $campaignData = $this->getClient()->getById($campaign->getSalesforceId(), $withCache);

        $this->updateCampaignFromSFData($campaign, $campaignData);

        try {
            $this->getEntityManager()->flush();
        } catch (\PDOException $e) {
            $this->logger?->error("PDOException generated trying to update campaign SFID {$campaign->getSalesforceId()} (reg no {$campaign->getCharity()->getRegulatorNumber()}); {$e->getMessage()}");
            throw $e;
        }

        $campaign->setSalesforceLastPull(new DateTime('now'));
        $this->getEntityManager()->persist($campaign);
        if ($autoSave) {
            $this->getEntityManager()->flush();
        }

        $this->logInfo('Done persisting ' . get_class($campaign) . ' ' . $salesforceId);
    }
}
