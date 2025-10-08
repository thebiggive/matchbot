<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Assert\Assertion;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<CampaignFunding>
 */
class CampaignFundingRepository extends EntityRepository
{
    /**
     * Get available-for-allocation `CampaignFunding`s, without a lock.
     *
     * Ordering is well-defined as far being champion funds first (currently given allocationOrder=100) then pledges
     * (given allocationOrder=200). The more specific ordering is arbitrary, determined by the order funds were first
     * read from the Salesforce implementation's API. This doesn't matter in effect because the allocations can't
     * mirror the reality of what happens after a campaign if not all pledges are used, which varies per charity. In
     * the case of pro-rata'ing the amount from each pledger, MatchBot's allocations cannot accurately reflect the
     * amount due at the end. It would not be feasible to track these proportional amounts during the allocation phase
     * because we would have to split amounts up constantly and it would break the decimal strings,
     * no-floating-point-maths approach we've taken to ensure accuracy.
     *
     * @param Campaign $campaign
     * @return CampaignFunding[]    Sorted in the order funds should be allocated
     */
    public function getAvailableFundings(Campaign $campaign, bool $forceNotBigGive = false): array
    {
        // Temporary option to allow for fixing wrong BG allocations.
        $extraCondition = $forceNotBigGive ? " AND cf.fund != 'a09WS00000BDhATYA1'" : '';

        $query = $this->getEntityManager()->createQuery('
            SELECT cf FROM MatchBot\Domain\CampaignFunding cf JOIN cf.fund fund
            WHERE :campaign MEMBER OF cf.campaigns ' . $extraCondition . '
            AND cf.amountAvailable > 0
            ORDER BY fund.allocationOrder, cf.id
        ');
        $query->setParameter('campaign', $campaign->getId());

        /** @var CampaignFunding[] $result */
        $result = $query->getResult();

        return $result;
    }

    /**
     * Returns all CampaignFunding associated with campaign, including those that have zero
     * funds available at this time.
     *
     * Order is just by CF ID, whereas {@see self::getAvailableFundings()} gives an ordered-by-allocation-order list.
     *
     * @return CampaignFunding[]
     */
    public function getAllFundingsForCampaign(Campaign $campaign): array
    {
        $query = $this->getEntityManager()->createQuery('
            SELECT cf FROM MatchBot\Domain\CampaignFunding cf
            WHERE :campaign MEMBER OF cf.campaigns
            ORDER BY cf.id
        ');
        $query->setParameter('campaign', $campaign->getId());

        /** @var CampaignFunding[] $result */
        $result = $query->getResult();

        return $result;
    }

    /**
     * Get the total amount available for a meta-campaign, ensuring that shared fundings
     * are only counted once even if they're linked to multiple campaigns.
     */
    public function getAmountAvailableForMetaCampaign(MetaCampaign $metaCampaign): Money
    {
        // First, get all unique CampaignFunding IDs for the meta-campaign
        $idQuery = $this->getEntityManager()->createQuery(dql: <<<'DQL'
            SELECT DISTINCT cf.id FROM MatchBot\Domain\CampaignFunding cf
            LEFT JOIN cf.campaigns campaign
            WHERE campaign.metaCampaignSlug = :slug
        DQL
        );
        $idQuery->setParameter('slug', $metaCampaign->getSlug()->slug);

        /** @var list<array{id: int}> $ids */
        $ids = $idQuery->getResult();

        if (empty($ids)) {
            return Money::zero(currency: $metaCampaign->getCurrency());
        }

        // Extract just the IDs into a flat array
        $fundingIds = array_map(fn($row) => $row['id'], $ids);

        // Then, sum the amounts for these unique IDs
        $sumQuery = $this->getEntityManager()->createQuery(dql: <<<'DQL'
            SELECT COALESCE(SUM(cf.amountAvailable), 0) as sum, cf.currencyCode 
            FROM MatchBot\Domain\CampaignFunding cf
            WHERE cf.id IN (:ids)
            GROUP BY cf.currencyCode
        DQL
        );
        $sumQuery->setParameter('ids', $fundingIds);

        /** @var list<array{sum: numeric-string, currencyCode: string}> $result */
        $result = $sumQuery->getResult();

        Assertion::maxCount($result, 1, 'Campaign Fundings in multiple currencies found for same metacampaign');

        if ($result === []) {
            return Money::zero(currency: $metaCampaign->getCurrency());
        }

        return Money::fromNumericString($result[0]['sum'], Currency::fromIsoCode($result[0]['currencyCode']));
    }

    public function getFunding(Fund $fund): ?CampaignFunding
    {
        $query = $this->getEntityManager()->createQuery('
            SELECT cf FROM MatchBot\Domain\CampaignFunding cf
            WHERE cf.fund = :fund
        ')->setMaxResults(1);
        $query->setParameter('fund', $fund->getId());
        $query->execute();

        /** @var ?CampaignFunding $result */
        $result = $query->getOneOrNullResult();

        return $result;
    }

    public function getFundingForCampaign(Campaign $campaign, Fund $fund): ?CampaignFunding
    {
        $query = $this->getEntityManager()->createQuery('
            SELECT cf FROM MatchBot\Domain\CampaignFunding cf
            WHERE :campaign MEMBER OF cf.campaigns
            AND cf.fund = :fund
        ')->setMaxResults(1);
        $query->setParameter('campaign', $campaign->getId());
        $query->setParameter('fund', $fund->getId());
        $query->execute();

        /** @var ?CampaignFunding $result */
        $result = $query->getOneOrNullResult();

        return $result;
    }

    public function zeroBigGiveWgmf25Funding(Campaign $campaign): void
    {
        $query = $this->getEntityManager()->createQuery('
            UPDATE MatchBot\Domain\CampaignFunding cf
            SET cf.amountAvailable = 0, cf.amountAllocated = 0
            WHERE :campaign MEMBER OF cf.campaigns
            AND cf.fund = :fund
        ');
        $query->setParameter('campaign', $campaign->getId());
        $query->setParameter('fund', 'a09WS00000BDhATYA1');

        $query->execute();
    }
}
