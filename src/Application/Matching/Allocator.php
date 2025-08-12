<?php

declare(strict_types=1);

namespace MatchBot\Application\Matching;

use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\Donations\Confirm;
use MatchBot\Application\Assertion;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\FundingWithdrawal;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockFactory;

class Allocator
{
    public function __construct(
        private Adapter $adapter,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private CampaignFundingRepository $campaignFundingRepository,
        private LockFactory $lockFactory,
    ) {
    }

    /**
     * Create all funding allocations, with `FundingWithdrawal` links to this donation, and safely update the funds'
     * available amount figures.
     *
     * @param Donation $donation
     * @psalm-return numeric-string Total amount of matching *newly* allocated. Return value is only used in
     *                              retrospective matching and redistribution commands - Donation::create does not take
     *                              return value.
     * @see CampaignFundingRepository::getAvailableFundings() for lock acquisition detail
     */
    public function allocateMatchFunds(Donation $donation): string
    {
        // We look up matching withdrawals to allow for the case where retrospective matching was required
        // and the donation is not new, and *some* (or full) matching already occurred. The collection of withdrawals
        // is most often empty (for new donations) so this will frequently be 0.00.
        $amountMatchedAtStart = $donation->getFundingWithdrawalTotal();

        $allocateStartTime = 0; // dummy value, should always be overwritten before usage.
        try {
            /** @var list<CampaignFunding> $likelyAvailableFunds */
            $likelyAvailableFunds = $this->campaignFundingRepository->getAvailableFundings($donation->getCampaign());

            foreach ($likelyAvailableFunds as $funding) {
                if ($funding->getCurrencyCode() !== $donation->currency()->isoCode()) {
                    throw new \UnexpectedValueException('Currency mismatch');
                }
            }

            $allocateStartTime = microtime(true);
            $newWithdrawals = $this->allocateFundsAndPrepareDBChanges($donation, $likelyAvailableFunds, $amountMatchedAtStart);
            $allocateEndTime = microtime(true);
        } catch (TerminalLockException $exception) {
            $waitTime = round(microtime(true) - (float)$allocateStartTime, 6);
            $this->logError(
                "Match allocate error: ID {$donation->getUuid()} got " . get_class($exception) .
                " after {$waitTime}s: {$exception->getMessage()}"
            );
            throw $exception; // Re-throw exception after logging the details if not recoverable
        }

        $amountNewlyMatched = '0.0';

        foreach ($newWithdrawals as $newWithdrawal) {
            $this->entityManager->persist($newWithdrawal);
            $donation->addFundingWithdrawal($newWithdrawal);
            $newWithdrawalAmount = $newWithdrawal->getAmount();

            $amountNewlyMatched = bcadd($amountNewlyMatched, $newWithdrawalAmount, 2);
        }

        try {
            // Flush `$newWithdrawals` if any and updates to DB copies of CampaignFundings.
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->adapter->releaseNewlyAllocatedFunds();

            // Ensure nothing later tries to persist the pending Donation or CampaignFunding changes.
            $this->entityManager->close();
            throw new DbErrorPreventedMatch(
                'Failed to flush DB for new withdrawals so donation UUID ' . $donation->getUuid()->toString() .
                ' did not get matching: ' . $exception->getMessage(),
            );
        }

        $this->logInfo('ID ' . $donation->getUuid()->toString() . ' allocated new match funds totalling ' . $amountNewlyMatched);
        $this->logInfo('Allocation took ' . (string) round($allocateEndTime - $allocateStartTime, 6) . ' seconds');

        return $amountNewlyMatched;
    }

    /**
     * This method is safe for general use only inside transations.
     *
     * Prefer {@see DonationService::releaseMatchFundsInTransaction()} if you're not updating internals and don't
     * have a reason otherwise.
     *
     * @psalm-internal MatchBot\Domain
     * @param Donation $donation
     * @throws TerminalLockException
     * @throws LockConflictedException in case there is a confirmation or pre-auth attempt happening at the same time.
     */
    public function releaseMatchFunds(Donation $donation): void
    {
        $startTime = microtime(true);
        try {
            $lock = $this->lockFactory->createLock(Confirm::donationConfirmLockKey($donation), autoRelease: true);
            $lock->acquire(blocking: false);
            $totalAmountReleased = $this->adapter->releaseAllFundsForDonation($donation);
            $this->entityManager->flush();
            $endTime = microtime(true);

            try {
                $this->removeAllFundingWithdrawalsForDonation($donation);
            } catch (DBALException $exception) {
                $this->logError('Doctrine could not remove withdrawals after maximum tries');
            }
        } catch (TerminalLockException $exception) {
            $waitTime = round(microtime(true) - $startTime, 6);
            $this->logError(
                'Match release error: ID ' . $donation->getUuid()->toString() . ' got ' . get_class($exception) .
                " after {$waitTime}s: {$exception->getMessage()}"
            );
            throw $exception; // Re-throw exception after logging the details if not recoverable
        } catch (LockConflictedException $conflictedException) {
            // presumably a conflict because someone is trying to confirm the donation at the same moment we're trying
            // to release its match funds. Lets do nothing and let them confirm. Although this shouldn't happen as the
            // FE should have predicted that the lock would expire.
            $this->logError(
                'Match release error: UUID ' . $donation->getUuid()->toString() . ' attempting to release funds while confirmation in progress'
            );
            throw $conflictedException;
        }

        $this->logInfo("Taking from ID {$donation->getUuid()} released match funds totalling {$totalAmountReleased}");
        $this->logInfo('Deallocation took ' . (string) round($endTime - $startTime, 6) . ' seconds');
    }

    /**
     * Attempt an allocation of funds. When all is well this:
     *
     * * updates the Redis authoritative store of fund balances,
     * * updates the CampaignFunding balances from those copies to match (in entity manager but not flushed yet); and
     * * updates the FundingWithdrawal records in the entity manager (also not flushed yet).
     *
     * @param Donation $donation
     * @param CampaignFunding[] $fundings   Fundings likely to have funds available. To be re-queried with a
     *                                      pessimistic write lock before allocation.
     *
     * @param numeric-string $amountMatchedAtStart Amount of match funds already allocated to the donation when we
     *                                              started.
     * @return FundingWithdrawal[]
     */
    private function allocateFundsAndPrepareDBChanges(Donation $donation, array $fundings, string $amountMatchedAtStart): array
    {
        $amountLeftToMatch = bcsub($donation->getAmount(), $amountMatchedAtStart, 2);
        $currentFundingIndex = 0;
        /** @var FundingWithdrawal[] $newWithdrawals Track these to persist to DB after the main allocation */
        $newWithdrawals = [];

        // Loop as long as there are still campaign funds not allocated and we have allocated less than the donation
        // amount
        while ($currentFundingIndex < count($fundings) && bccomp($amountLeftToMatch, '0.00', 2) === 1) {
            $funding = $fundings[$currentFundingIndex];
            $startAmountAvailable = $fundings[$currentFundingIndex]->getAmountAvailable();

            if (bccomp($funding->getAmountAvailable(), $amountLeftToMatch, 2) === -1) {
                $amountToAllocateNow = $startAmountAvailable;
            } else {
                $amountToAllocateNow = $amountLeftToMatch;
            }

            $newTotal = '[new total not defined]';
            try {
                $newTotal = $this->adapter->subtractAmount($funding, $amountToAllocateNow);
                $amountAllocated = $amountToAllocateNow; // If no exception thrown
            } catch (LessThanRequestedAllocatedException $exception) {
                $amountAllocated = $exception->getAmountAllocated();
                $this->logInfo(
                    "Amount available from funding ID {$funding->getId()} changed: - got $amountAllocated " .
                    "of requested $amountToAllocateNow"
                );
            }

            $amountLeftToMatch = bcsub($amountLeftToMatch, $amountAllocated, 2);

            if (bccomp($amountAllocated, '0.00', 2) === 1) {
                $withdrawal = new FundingWithdrawal($funding);
                $withdrawal->setDonation($donation);
                $withdrawal->setAmount($amountAllocated);
                $newWithdrawals[] = $withdrawal;
                $this->logInfo("Successfully withdrew $amountAllocated from funding ID {$funding->getId()} for UUID {$donation->getUuid()}");
                $this->logInfo("New fund total for {$funding->getId()}: $newTotal");
            }

            $currentFundingIndex++;
        }

        return $newWithdrawals;
    }

    /**
     * Normally called just as part of releaseMatchFunds which also releases the funds in Redis. But
     * used separately in case of a crash when we would need to release the funds in Redis whether or not
     * we have any FundingWithdrawals in MySQL.
     */
    private function removeAllFundingWithdrawalsForDonation(Donation $donation): void
    {
        $this->entityManager->wrapInTransaction(function () use ($donation) {
            foreach ($donation->getFundingWithdrawals() as $fundingWithdrawal) {
                $this->entityManager->remove($fundingWithdrawal);
            }
        });
    }

    private function logError(string $message): void
    {
        $this->logger->error($message);
    }

    private function logInfo(string $message): void
    {
        $this->logger->info($message);
    }
}
