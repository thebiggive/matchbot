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
        // see https://github.com/laravel-doctrine/fluent/issues/51 for using findOneBy on a field of an embeddable.
        return $this->findOneBy(['stripeCustomerId.stripeCustomerId' => $stripeAccountId->stripeCustomerId]);
    }

    public function findByPersonId(PersonId $personId): ?DonorAccount
    {
        return $this->findOneBy(['uuid' => $personId->id]);
    }
}
