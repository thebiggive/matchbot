<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityRepository;

class CampaignFundingRepository extends EntityRepository
{
    /**
     * Get available-for-allocation `CampaignFunding`s, without a lock.
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

    /**
     * Get `CampaignFunding`s with a `FundingWithdrawal` linked to the given donation, with a pessimistic
     * write lock so their totals can be safely updated alongside deleting the withdrawals.
     * @see CampaignFundingRepository::getAvailableFundings() for more explanation.
     *
     * @param Donation $donation
     * @return CampaignFunding[]
     * @throws \Doctrine\ORM\TransactionRequiredException if called outside a surrounding transaction
     */
    public function getDonationFundings(Donation $donation)
    {
        $campaignFundingIds = [];
        foreach ($donation->getFundingWithdrawals() as $fundingWithdrawal) {
            $campaignFundingIds[] = $fundingWithdrawal->getCampaignFunding()->getId();
        }

        return $this->getManyWithWriteLock(
            $this->findBy(['id' => $campaignFundingIds])
        );
    }

    public function getFunding(Campaign $campaign, Fund $fund): ?CampaignFunding
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

    /**
     * Use inside a transaction which will change a fund's `amountAvailable`.
     *
     * @link https://stackoverflow.com/questions/12971249/doctrine2-orm-select-for-update/17721736
     * @link https://www.doctrine-project.org/projects/doctrine-orm/en/2.6/reference/transactions-and-concurrency.html#locking-support
     *
     * @param CampaignFunding $campaignFunding
     * @return CampaignFunding The same object passed in but with current data and a write-ready lock
     */
    public function getOneWithWriteLock(CampaignFunding $campaignFunding): CampaignFunding
    {
        return $this->find($campaignFunding->getId(), LockMode::PESSIMISTIC_WRITE);
    }

    /**
     * @param CampaignFunding[] $campaignFundings
     * @return CampaignFunding[] The `CampaignFunding`s passed in, but with the latest data + pessimistic write lock
     */
    private function getManyWithWriteLock(array $campaignFundings): array
    {
        $fundingsLocked = [];
        foreach ($campaignFundings as $funding) {
            $fundingsLocked[] = $this->getOneWithWriteLock($funding);
        }

        return $fundingsLocked;
    }
}
