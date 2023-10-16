<?php

namespace MatchBot\Domain;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<DonorAccount>
 */
class DonorAccountRepository extends EntityRepository
{
    public function save(DonorAccount $donorAccount): void
    {
        $this->getEntityManager()->persist($donorAccount);
        $this->getEntityManager()->flush();
    }
}