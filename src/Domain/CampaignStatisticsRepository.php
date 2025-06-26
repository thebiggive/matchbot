<?php

namespace MatchBot\Domain;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;

class CampaignStatisticsRepository
{
    /** @var EntityRepository<CampaignStatistics>  */
    private EntityRepository $doctrineRepository;

    public function __construct(private EntityManagerInterface $em)
    {
        $this->doctrineRepository = $em->getRepository(CampaignStatistics::class);
    }

    /**
     * Creates or finds + updates a {@see CampaignStatistics} record; doesn't flush, so callers need to when done
     * building stats.
     */
    public function updateStatistics(Campaign $campaign, Money $amountRaised, Money $matchFundsUsed): void
    {
        $statistics = $this->doctrineRepository->findOneBy(['campaign' => $campaign]);

        if ($statistics) {
            $statistics->setAmountRaised($amountRaised);
            $statistics->setMatchFundsUsed($matchFundsUsed);
        } else {
            $statistics = new CampaignStatistics($campaign, $amountRaised, $matchFundsUsed);
            $this->em->persist($statistics);
        }
    }
}
