<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Domain\DonationRepository;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Expire match funding allocations, by hard-deleting `FundingWithdrawals` for donations that have been
 * Pending for more than the reservation time.
 *
 * Donations may still be completed after the expiry time but will not receive match funds.
 */
class ExpireMatchFunds extends LockingCommand
{
    protected static $defaultName = 'matchbot:expire-match-funds';

    private DonationRepository $donationRepository;

    public function __construct(DonationRepository $donationRepository)
    {
        $this->donationRepository = $donationRepository;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Frees up match funding from stale Pending donations');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $toRelease = $this->donationRepository->findWithExpiredMatching();

        foreach ($toRelease as $donation) {
            $this->donationRepository->releaseMatchFunds($donation);
        }

        $numberExpired = count($toRelease);
        $output->writeln("Released $numberExpired donations' matching");

        return 0;
    }
}
