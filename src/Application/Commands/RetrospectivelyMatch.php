<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Domain\DonationRepository;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Find complete donations to matched campaigns with less matching than their full value, from the past 48 hours,
 * and allocate any newly-available match funds to them.
 */
class RetrospectivelyMatch extends LockingCommand
{
    protected static $defaultName = 'matchbot:retrospectively-match';

    /** @var DonationRepository */
    private $donationRepository;

    public function __construct(DonationRepository $donationRepository)
    {
        $this->donationRepository = $donationRepository;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription(
            "Allocates matching from the last 48 hours' donations if they missed it due to pending reservations"
        );
    }

    protected function doExecute(OutputInterface $output)
    {
        $toCheckForMatching = $this->donationRepository->findRecentNotFullyMatchedToMatchCampaigns();
        $numChecked = count($toCheckForMatching);
        $distinctCampaignIds = [];
        $numWithMatchingAllocated = 0;
        $totalNewMatching = '0.00';

        foreach ($toCheckForMatching as $donation) {
            $amountAllocated = $this->donationRepository->allocateMatchFunds($donation);

            if (bccomp($amountAllocated, '0.00', 2) === 1) {
                $this->donationRepository->push($donation, false);
                $numWithMatchingAllocated++;
                $totalNewMatching = bcadd($totalNewMatching, $amountAllocated, 2);

                if (!in_array($donation->getCampaign()->getId(), $distinctCampaignIds, true)) {
                    $distinctCampaignIds[] = $donation->getCampaign()->getId();
                }
            }
        }

        $numDistinctCampaigns = count($distinctCampaignIds);

        $output->writeln(
            "Retrospectively matched $numWithMatchingAllocated of $numChecked donations. " .
            "£$totalNewMatching total new matching, across $numDistinctCampaigns campaigns."
        );
    }
}
