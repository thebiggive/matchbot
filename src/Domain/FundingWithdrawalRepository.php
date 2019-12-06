<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\EntityRepository;

class FundingWithdrawalRepository extends EntityRepository
{
    /**
     * @param CampaignFunding $campaignFunding
     * @return string Total of FundingWithdrawals (including active reservations) as bcmath-ready string
     */
    public function getWithdrawalsTotal(CampaignFunding $campaignFunding): string
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('SUM(fw.amount)')
            ->from(FundingWithdrawal::class, 'fw')
            ->where('fw.campaignFunding = :campaignFunding')
            ->setParameter('campaignFunding', $campaignFunding->getId());

        return (string) $qb->getQuery()->getSingleScalarResult();
    }
}
