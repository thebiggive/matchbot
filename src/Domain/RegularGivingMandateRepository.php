<?php

namespace MatchBot\Domain;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * Note this is different from other repositories we have so far as it encapsulates Doctrine's repository
 * class instead of extending it, so that it can present just the specific API that we actually want and ensure
 * that all the ways we use are listed as methods below.
 *
 * If we like this pattern we could try applying it
 * to all repos automatically - see
 * https://getrector.com/blog/how-to-instantly-decouple-symfony-doctrine-repository-inheritance-to-clean-composition
 */
class RegularGivingMandateRepository
{
    /** @var EntityRepository<RegularGivingMandate>  */
    private EntityRepository $doctrineRepository;

    public function __construct(private EntityManagerInterface $em)
    {
        $this->doctrineRepository = $em->getRepository(RegularGivingMandate::class);
    }

    /**
     * Only really useful during very early development, likely to be deleted soon.
     *
     * @return list<RegularGivingMandate>
     */
    public function findAll(): array
    {
        return $this->doctrineRepository->findAll();
    }
}
