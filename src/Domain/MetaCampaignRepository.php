<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

/**
 * @psalm-suppress UnusedProperty - likely to be used soon
*/
class MetaCampaignRepository
{
    /** @var EntityRepository<MetaCampaign>  */
    private EntityRepository $doctrineRepository;

    public function __construct(private EntityManagerInterface $em)  // @phpstan-ignore property.onlyWritten
    {
        $this->doctrineRepository = $em->getRepository(MetaCampaign::class);
    }

    public function getBySlug(MetaCampaignSlug $slug): ?MetaCampaign
    {
        return $this->doctrineRepository->findOneBy(['slug' => $slug->slug]);
    }
}
