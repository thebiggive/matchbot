<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityRepository;

class CampaignFundingRepository extends EntityRepository
{
    /**
     * Get available-for-allocation `CampaignFunding`s with a pessimistic write lock. Suitable for us inside a
     * transaction which will reduce the `amountAvailable` and create a `FundingWithdrawal`.
     *
     * @param Campaign $campaign
     * @return CampaignFunding[]
     * @throws \Doctrine\ORM\TransactionRequiredException if you call this outside a surrounding transaction
     * @link https://stackoverflow.com/questions/12971249/doctrine2-orm-select-for-update/17721736
     * @link https://www.doctrine-project.org/projects/doctrine-orm/en/2.6/reference/transactions-and-concurrency.html#locking-support
     */
    public function getAvailableFundings(Campaign $campaign): array
    {
        $query = $this->getEntityManager()->createQuery('
            SELECT cf FROM CampaignFunding cf
            WHERE cf.campaign = :campaign
            AND cf.amountAvailable > 0
            ORDER BY cf.order, cf.id
        ');
        $query->setParameter('campaign', $campaign);
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->execute();

        return $query->getArrayResult();
    }
}
