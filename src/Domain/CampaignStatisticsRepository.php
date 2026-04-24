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
    public function __construct(private EntityManagerInterface $em)
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
            return CampaignStatistics::zeroPlaceholder($campaign, new \DateTimeImmutable('now'));
        }

        return $statistics;
    }

    public function updateApproxStatuses(): void
    {
        $preview = CampaignStatus::Preview->value;

        $active = CampaignStatus::Active->value;

        // find stats for Campaigns about to become active
        $query = $this->em->createQuery(
            <<<DQL
                SELECT IDENTITY(cs)
                FROM MatchBot\Domain\CampaignStatistics cs
                JOIN cs.campaign c
                WHERE c.isPublished = true
                AND c.startDate < :tommorrow
                AND c.endDate > CURRENT_TIMESTAMP()
                AND cs.approxStatus = '$preview'
            DQL
        );
        $query->setParameter('tommorrow', new \DateTimeImmutable('+1 day'));
        $campaignsStatisticstoSetActive = $query->getSingleColumnResult();

        // mark them active in the stats table
        $query = $this->em->createQuery(
            <<<DQL
                UPDATE MatchBot\Domain\CampaignStatistics cs
                SET cs.approxStatus = '$active'
                WHERE cs.campaign in (:campaignsStatisticstoSetActive)
            DQL
        );
        $query->setParameter('campaignsStatisticstoSetActive', $campaignsStatisticstoSetActive);

        $query->execute();

        $expired = CampaignStatus::Expired->value;

        // find stats for Campaigns that have expired
        $query = $this->em->createQuery(
            <<<DQL
                SELECT IDENTITY(cs)
                FROM MatchBot\Domain\CampaignStatistics cs
                JOIN cs.campaign c
                WHERE c.isPublished = true
                AND c.endDate < CURRENT_TIMESTAMP()
                AND cs.approxStatus != '$expired'
            DQL
        );
        $campaignsStatisticstoSetExpired = $query->getSingleColumnResult();

        // mark them expired in the stats table
        $query = $this->em->createQuery(
            <<<DQL
                UPDATE MatchBot\Domain\CampaignStatistics cs
                SET cs.approxStatus = '$expired'
                WHERE cs.campaign in (:campaignsStatisticstoSetExpired)
            DQL
        );
        $query->setParameter('campaignsStatisticstoSetExpired', $campaignsStatisticstoSetExpired);

        $query->execute();
    }
}
