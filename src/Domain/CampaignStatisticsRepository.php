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
    public function __construct(
        private EntityManagerInterface $em,
        private MatchFundsService $matchFundsService,
    ) {
        $this->doctrineRepository = $em->getRepository(CampaignStatistics::class);
    }

    /**
     * Creates or finds + updates a {@see CampaignStatistics} record; doesn't flush, so callers need to when done
     * building stats.
     */
    public function updateStatistics(
        Campaign $campaign,
        Money $donationSum,
        Money $amountRaised,
        Money $matchFundsUsed,
    ): void {
        $statistics = $this->doctrineRepository->findOneBy(['campaign' => $campaign]);

        if ($statistics) {
            $statistics->setAmountRaised($amountRaised);
            $statistics->setMatchFundsUsed($matchFundsUsed);
        } else {
            $statistics = new CampaignStatistics(
                campaign: $campaign,
                donationSum: $donationSum,
                amountRaised: $amountRaised,
                matchFundsUsed: $matchFundsUsed,
                matchFundsTotal: $this->matchFundsService->getTotalFunds($campaign),
            );

            $this->em->persist($statistics);
        }
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
