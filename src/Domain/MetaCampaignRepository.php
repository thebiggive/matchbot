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

    public function __construct(private EntityManagerInterface $em)
    {
        $this->doctrineRepository = $em->getRepository(MetaCampaign::class);
    }

    public function getBySlug(MetaCampaignSlug $slug): ?MetaCampaign
    {
        return $this->doctrineRepository->findOneBy(['slug' => $slug->slug]);
    }

    public function countCompleteDonationsToMetaCampaign(MetaCampaign $metaCampaign): int
    {
        $query = $this->em->createQuery(<<<'DQL'
            SELECT COUNT(d.id)
            FROM MatchBot\Domain\Donation d JOIN d.campaign c
            WHERE c.metaCampaignSlug = :slug
            AND d.donationStatus IN (:collectedStatuses)
        DQL
        );

        $query->setParameter('slug', $metaCampaign->getSlug()->slug);

        $query->setParameter('collectedStatuses', DonationStatus::SUCCESS_STATUSES);

        $count = (int)$query->getSingleScalarResult();

        \assert($count >= 0);

        return $count;
    }
}
