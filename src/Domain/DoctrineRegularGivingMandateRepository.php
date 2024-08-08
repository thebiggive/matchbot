<?php

namespace MatchBot\Domain;

use Doctrine\ORM\EntityRepository;

/**
 * @template-extends EntityRepository<RegularGivingMandate>
 */
class DoctrineRegularGivingMandateRepository extends EntityRepository implements RegularGivingMandateRepository
{
}
