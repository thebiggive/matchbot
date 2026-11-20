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
        $preview = CampaignStatus::Preview;
        $expired = CampaignStatus::Expired;

        // Find stats for Camppaigns that are Expired yet in the future and proceeding, e.g. due to temporarily missing SF data
        $query = $this->em->createQuery(
            <<<DQL
                SELECT IDENTITY(cs)
                FROM MatchBot\Domain\CampaignStatistics cs
                JOIN cs.campaign c
                WHERE c.isPublished = true
                AND c.startDate > CURRENT_TIMESTAMP()
                AND cs.approxStatus = '$expired->value'
            DQL
        );
        /** @var list<int> $campaignsStatisticstoSetPreview Identity of `cs` is the campaign ID */
        $campaignsStatisticstoSetPreview = $query->getSingleColumnResult();
        $this->setCampaignStatus($preview, $campaignsStatisticstoSetPreview);

        // find stats for Campaigns about to become active
        $query = $this->em->createQuery(
            <<<DQL
                SELECT IDENTITY(cs)
                FROM MatchBot\Domain\CampaignStatistics cs
                JOIN cs.campaign c
                WHERE c.isPublished = true
                AND c.startDate < :tomorrow
                AND c.endDate > CURRENT_TIMESTAMP()
                AND cs.approxStatus = '$preview->value'
            DQL
        );
        $query->setParameter('tomorrow', new \DateTimeImmutable('+1 day'));
        /** @var list<int> $campaignsStatisticstoSetActive Identity of `cs` is the campaign ID */
        $campaignsStatisticstoSetActive = $query->getSingleColumnResult();

        // mark them active in the stats table
        $this->setCampaignStatus(CampaignStatus::Active, $campaignsStatisticstoSetActive);

        // find stats for Campaigns that have expired
        $query = $this->em->createQuery(
            <<<DQL
                SELECT IDENTITY(cs)
                FROM MatchBot\Domain\CampaignStatistics cs
                JOIN cs.campaign c
                WHERE c.isPublished = true
                AND c.endDate < CURRENT_TIMESTAMP()
                AND cs.approxStatus != '$expired->value'
            DQL
        );
        /** @var list<int> $campaignsStatisticstoSetExpired Identity of `cs` is the campaign ID */
        $campaignsStatisticstoSetExpired = $query->getSingleColumnResult();

        // mark them expired in the stats table
        $this->setCampaignStatus($expired, $campaignsStatisticstoSetExpired);
    }

    /**
     * @param list<int> $campaignIds Array of Campaign IDs via a getSingleColumnResult()
     */
    private function setCampaignStatus(CampaignStatus $status, array $campaignIds): void
    {
        $query = $this->em->createQuery(
            <<<DQL
                UPDATE MatchBot\Domain\CampaignStatistics cs
                SET cs.approxStatus = '$status->value'
                WHERE cs.campaign in (:campaignIds)
            DQL
        );
        $query->setParameter('campaignIds', $campaignIds);
        $query->execute();
    }
}
