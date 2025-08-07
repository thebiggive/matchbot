<?php

declare(strict_types=1);

namespace MatchBot\Application\Commands;

use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;

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
        private DonationRepository $donationRepository,
        private DonationService $donationService,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setDescription('Frees up match funding from stale Pending donations');
    }

    #[\Override]
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $toRelease = $this->donationRepository->findWithExpiredMatching(new \DateTimeImmutable('now'));

        foreach ($toRelease as $donationUUID) {
            try {
                $this->donationService->releaseMatchFundsInTransaction($donationUUID);
            } catch (LockConflictedException $conflictedException) {
                // It's OK, we won't release the funds now on this donation which seems to be in the process of being
                // confirmed. Either it will be confirmed right now, or we can try again to releaese funds when this runs
                // again next minute if the confirmation fails.
            }
        }

        $numberExpired = count($toRelease);
        $output->writeln("Released $numberExpired donations' matching");

        return 0;
    }
}
