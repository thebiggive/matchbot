<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\LockMode;
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
     * @throws \Doctrine\ORM\TransactionRequiredException if called this outside a surrounding transaction
     */
    public function getAvailableFundings(Campaign $campaign): array
    {
        $query = $this->getEntityManager()->createQuery('
            SELECT cf FROM MatchBot\Domain\CampaignFunding cf
            WHERE :campaign MEMBER OF cf.campaigns
            AND cf.amountAvailable > 0
            ORDER BY cf.allocationOrder, cf.id
        ');
        $query->setParameter('campaign', $campaign->getId());

        return $query->getResult();
    }

    public function getFunding(Fund $fund): ?CampaignFunding
    {
        $query = $this->getEntityManager()->createQuery('
            SELECT cf FROM MatchBot\Domain\CampaignFunding cf
            WHERE cf.fund = :fund
        ')->setMaxResults(1);
        $query->setParameter('fund', $fund->getId());
        $query->execute();

        return $query->getOneOrNullResult();
    }

    public function getFundingForCampaign(Campaign $campaign, Fund $fund): ?CampaignFunding
    {
        $query = $this->getEntityManager()->createQuery('
            SELECT cf FROM MatchBot\Domain\CampaignFunding cf
            WHERE :campaign MEMBER OF cf.campaigns
            AND cf.fund = :fund
        ')->setMaxResults(1);
        $query->setParameter('campaign', new ArrayCollection([$campaign->getId()]));
        $query->setParameter('fund', $fund->getId());
        $query->execute();

        return $query->getOneOrNullResult();
    }
}
