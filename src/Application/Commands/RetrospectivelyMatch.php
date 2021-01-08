<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use DateTime;
use MatchBot\Domain\DonationRepository;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Find complete donations to matched campaigns with less matching than their full value, from the past 48 hours,
 * and allocate any newly-available match funds to them.
 */
class RetrospectivelyMatch extends LockingCommand
{
    protected static $defaultName = 'matchbot:retrospectively-match';

    public function __construct(
        private DonationRepository $donationRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription(
            "Allocates matching from the last N days' donations if missed due to pending reservations, refunds etc."
        );
        $this->addArgument(
            'days-back',
            InputArgument::REQUIRED,
            'Number of days back to look for donations that could be matched.'
        );
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        if (!is_numeric($input->getArgument('days-back'))) {
            $output->writeln('Cannot proceed with non-numeric days-back argument');
            return 1;
        }

        $daysBack = round($input->getArgument('days-back'));
        $output->writeln("Looking at past $daysBack days' donations");

        $sinceDate = (new DateTime('now'))->sub(new \DateInterval("P{$daysBack}D"));
        $toCheckForMatching = $this->donationRepository->findRecentNotFullyMatchedToMatchCampaigns($sinceDate);

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

        return 0;
    }
}
