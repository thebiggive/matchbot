<?php

namespace MatchBot\Domain;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

class CampaignStatisticsRepository
{
    /** @var EntityRepository<CampaignStatistics>  */
    private EntityRepository $doctrineRepository;

    /**
     * @psalm-suppress PossiblyUnusedMethod Called by DI container
     */
    public function __construct(EntityManagerInterface $em)
    {
        $this->doctrineRepository = $em->getRepository(CampaignStatistics::class);
    }

    /**
     * With figures all zero if no donations or match funds used yet *or* if first stats calculation
     * has not yet run.
     */
    public function getStatistics(Campaign $campaign): CampaignStatistics
    {
        $statistics = $this->doctrineRepository->findOneBy(['campaign' => $campaign]);

        if (!$statistics) {
            return CampaignStatistics::zeroPlaceholder($campaign);
        }

        return $statistics;
    }
}
