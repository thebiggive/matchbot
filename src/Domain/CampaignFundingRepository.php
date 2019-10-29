<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityRepository;

class CampaignFundingRepository extends EntityRepository
{
    /**
     * Get available-for-allocation `CampaignFunding`s with a pessimistic write lock. Suitable for use inside a
     * transaction which will reduce the `amountAvailable` and create a `FundingWithdrawal`.
     *
     * @link https://stackoverflow.com/questions/12971249/doctrine2-orm-select-for-update/17721736
     * @link https://www.doctrine-project.org/projects/doctrine-orm/en/2.6/reference/transactions-and-concurrency.html#locking-support
     *
     * @param Campaign $campaign
     * @return CampaignFunding[]
     * @throws \Doctrine\ORM\TransactionRequiredException if you call this outside a surrounding transaction
     */
    public function getAvailableFundings(Campaign $campaign): array
    {
        $query = $this->getEntityManager()->createQuery('
            SELECT cf FROM MatchBot\Domain\CampaignFunding cf
            WHERE :campaign MEMBER OF cf.campaigns
            AND cf.amountAvailable > 0
            ORDER BY cf.order, cf.id
        ');
        $query->setParameter('campaign', new ArrayCollection([$campaign]));
        $query->setLockMode(LockMode::PESSIMISTIC_WRITE);
        $query->execute();

        return $query->getArrayResult();
    }
}
