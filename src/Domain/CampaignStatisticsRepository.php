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

        $query = $this->em->createQuery(
            <<<DQL
                UPDATE MatchBot\Domain\CampaignStatistics cs
                JOIN cs.campaign
                SET cs.approxStatus = '$active'
                WHERE cs.campaign.published
                AND cs.campaign.startDate < DATE_SUB(NOW(), INTERVAL 1 DAY)
                AND cs.campaign.endDate > NOW();
                AND cs.approxStatus = '$preview'
            DQL
        );

        $expired = CampaignStatus::Expired->value;
        $query->execute();

        $query = $this->em->createQuery(
            <<<DQL
                UPDATE MatchBot\Domain\CampaignStatistics cs
                JOIN cs.campaign
                SET cs.approxStatus = '$expired'
                WHERE cs.campaign.published
                AND cs.campaign.startDate < DATE_SUB(NOW(), INTERVAL 1 DAY)
                AND cs.campaign.endDate > NOW();
                AND cs.approxStatus = '$preview'
            DQL
        );

        $query->execute();
    }
}
