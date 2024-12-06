<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Domain\DonationRepository;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Expire match funding allocations, by hard-deleting `FundingWithdrawals` for donations that have been
 * Pending for more than the reservation time and releasing funds with the real-time adapter.
 *
 * Donations may still be completed after the expiry time but will not receive match funds.
 */
class ExpireMatchFunds extends LockingCommand
{
    protected static $defaultName = 'matchbot:expire-match-funds';

    public function __construct(
        private DonationRepository $donationRepository
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Frees up match funding from stale Pending donations');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $toRelease = $this->donationRepository->findWithExpiredMatching(new \DateTimeImmutable('now'));

        $output->writeln("DEBUG: about to sleep");
        $donationIdsAndStatuses = array_map(fn($donation) => $donation->getUuid() . ' ' . $donation->getDonationStatus()->value, $toRelease);
        foreach ($donationIdsAndStatuses as $donationIdAndStatus) {
            $output->writeln("DEBUG: next donation: $donationIdAndStatus");
        }
//        sleep(60);
        $output->writeln("DEBUG: waking up");

        foreach ($toRelease as $donation) {
            $this->donationRepository->safelyReleaseMatchFunds(Uuid::fromString($donation->getUuid()));
        }

        $numberExpired = count($toRelease);
        $output->writeln("Released $numberExpired donations' matching");

        return 0;
    }
}
