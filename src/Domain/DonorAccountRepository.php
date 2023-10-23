<?php

namespace MatchBot\Domain;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<DonorAccount>
 */
class DonorAccountRepository extends EntityRepository
{
    /**
     * @throws UniqueConstraintViolationException if we already have a donor account with the same Stripe Customer ID.
     */
    public function save(DonorAccount $donorAccount): void
    {
        $this->getEntityManager()->persist($donorAccount);
        $this->getEntityManager()->flush();
    }

    public function findByStripeIdOrNull(StripeCustomerId $stripeAccountId): ?DonorAccount
    {
        return $this->findOneBy(['stripeCustomerId' => $stripeAccountId->stripeCustomerId]);
    }
}