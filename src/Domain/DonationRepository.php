<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\DBAL\Exception\RetryableException;
use GuzzleHttp\Exception\ClientException;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching;
use MatchBot\Client\BadRequestException;
use MatchBot\Domain\DomainException\DomainLockContentionException;
use Ramsey\Uuid\Doctrine\UuidGenerator;

class DonationRepository extends SalesforceWriteProxyRepository
{
    /** @var CampaignRepository */
    private $campaignRepository;
    /** @var FundRepository */
    private $fundRepository;
    /** @var int */
    private $expirySeconds = 17 * 60; // 17 minutes: 15 min official timed window plus 2 mins grace.
    /** @var int When using a locking matching adapter, maximum number of tries for real-time operations */
    private $maxLockTries = 5;
    /**
     * @var int For non-matching updates that always use Doctrine, maximum number of times to try again when
     *          Doctrine reports that the error is recoverable and that retrying makes sense
     */
    private $maxDoctrineDeadlockRetries = 3;
    /** @var Matching\Adapter */
    private $matchingAdapter;
    /** @var Donation[] Tracks donations to persist outside the time-critical transaction / lock window */
    private $queuedForPersist;

    public function setMatchingAdapter(Matching\Adapter $adapter)
    {
        $this->matchingAdapter = $adapter;
    }

    /**
     * @param Donation $donation
     * @return bool
     */
    public function doCreate(SalesforceWriteProxy $donation): bool
    {
        try {
            $salesforceDonationId = $this->getClient()->create($donation);
            $donation->setSalesforceId($salesforceDonationId);
        } catch (BadRequestException $exception) {
            return false;
        }

        return true;
    }

    /**
     * @param Donation $donation
     * @return bool
     */
    public function doUpdate(SalesforceWriteProxy $donation): bool
    {
        return $this->getClient()->put($donation);
    }

    public function buildFromApiRequest(DonationCreate $donationData): Donation
    {
        /** @var Campaign $campaign */
        $campaign = $this->campaignRepository->findOneBy(['salesforceId' => $donationData->projectId]);

        if (!$campaign) {
            // Fetch data for as-yet-unknown campaigns on-demand
            $this->logInfo("Loading unknown campaign ID {$donationData->projectId} on-demand");
            $campaign = new Campaign();
            $campaign->setSalesforceId($donationData->projectId);
            try {
                $campaign = $this->campaignRepository->pull($campaign);
            } catch (ClientException $exception) {
                throw new \UnexpectedValueException('Campaign does not exist');
            }
            $this->fundRepository->pullForCampaign($campaign);

            $this->getEntityManager()->flush();
        }

        $donation = new Donation();
        $donation->setDonationStatus('Pending');
        $donation->setUuid((new UuidGenerator())->generate($this->getEntityManager(), $donation));
        $donation->setCampaign($campaign); // Charity & match expectation determined implicitly from this
        $donation->setAmount((string) $donationData->donationAmount);
        $donation->setGiftAid($donationData->giftAid);
        $donation->setCharityComms($donationData->optInCharityEmail);
        $donation->setTbgComms($donationData->optInTbgEmail);

        return $donation;
    }

    /**
     * Create all funding allocations, with `FundingWithdrawal` links to this donation, and safely update the funds'
     * available amount figures.
     *
     * @param Donation $donation
     * @return string Total amount of matching allocated
     * @see CampaignFundingRepository::getAvailableFundings() for lock acquisition detail
     */
    public function allocateMatchFunds(Donation $donation): string
    {
        $allocationDone = false;
        $allocationTries = 0;
        while (!$allocationDone && $allocationTries < $this->maxLockTries) {
            try {
                // We need write-ready locks for `CampaignFunding`s but also to keep the time we have them as short
                // as possible, so get the prelimary list without a lock, before the transaction.

                // Get these without a lock initially
                $likelyAvailableFunds = $this->getEntityManager()
                    ->getRepository(CampaignFunding::class)
                    ->getAvailableFundings($donation->getCampaign());

                $lockStartTime = microtime(true);
                $newWithdrawals = $this->matchingAdapter->runTransactionally(
                    function () use ($donation, $likelyAvailableFunds) {
                        return $this->safelyAllocateFunds($donation, $likelyAvailableFunds);
                    }
                );
                $lockEndTime = microtime(true);

                $this->persistQueuedDonations();

                // We end the transaction prior to inserting the funding withdrawal records, to keep the lock time
                // short. These are new entities, so except in a system crash the withdrawal totals will almost
                // immediately match the amount deducted from the fund.
                $amountMatched = '0.0';
                foreach ($newWithdrawals as $newWithdrawal) {
                    $this->getEntityManager()->persist($newWithdrawal);
                    $donation->addFundingWithdrawal($newWithdrawal);
                    $amountMatched = bcadd($amountMatched, $newWithdrawal->getAmount(), 2);
                }
                $this->flushResiliently();

                $allocationDone = true;
            } catch (Matching\RetryableLockException $exception) {
                $allocationTries++;
                $waitTime = round(microtime(true) - $lockStartTime, 6);
                $this->logError(
                    "Match allocate RECOVERABLE error: ID {$donation->getUuid()} got " . get_class($exception) .
                    " after {$waitTime}s on try #$allocationTries: {$exception->getMessage()}"
                );
                usleep(random_int(0, 1000000)); // Wait between 0 and 1 seconds before retrying
            } catch (Matching\TerminalLockException $exception) { // Includes non-retryable `DBALException`s
                $waitTime = round(microtime(true) - $lockStartTime, 6);
                $this->logError(
                    "Match allocate FINAL error: ID {$donation->getUuid()} got " . get_class($exception) .
                    " after {$waitTime}s on try #$allocationTries: {$exception->getMessage()}"
                );
                throw $exception; // Re-throw exception after logging the details if not recoverable
            }
        }

        if (!$allocationDone) {
            $this->logger->error(
                "Match allocate FINAL error: ID {$donation->getUuid()} failed matching after $allocationTries tries"
            );

            throw new DomainLockContentionException();
        }

        $this->logInfo('ID ' . $donation->getUuid() . ' allocated match funds totalling ' . $amountMatched);

        // Monitor allocation times so we can get a sense of how risky the locking behaviour is with different DB sizes
        $this->logInfo('Allocation took ' . round($lockEndTime - $lockStartTime, 6) . ' seconds');

        return $amountMatched;
    }

    public function releaseMatchFunds(Donation $donation): void
    {
        $releaseTries = 0;
        $releaseDone = false;

        while (!$releaseDone && $releaseTries < $this->maxLockTries) {
            $totalAmountReleased = '0.00';
            try {
                $lockStartTime = microtime(true);
                $totalAmountReleased = $this->matchingAdapter->runTransactionally(
                    function () use ($donation, $totalAmountReleased) {
                        foreach ($donation->getFundingWithdrawals() as $fundingWithdrawal) {
                            $funding = $fundingWithdrawal->getCampaignFunding();
                            $this->matchingAdapter->addAmount($funding, $fundingWithdrawal->getAmount());
                            $totalAmountReleased = bcadd($totalAmountReleased, $fundingWithdrawal->getAmount(), 2);
                            $this->logInfo("Released {$fundingWithdrawal->getAmount()} to funding {$funding->getId()}");
                        }

                        return $totalAmountReleased;
                    }
                );
                $lockEndTime = microtime(true);
                $releaseDone = true;

                foreach ($donation->getFundingWithdrawals() as $fundingWithdrawal) {
                    $this->getEntityManager()->remove($fundingWithdrawal);
                }
                $this->flushResiliently();
            } catch (Matching\RetryableLockException $exception) {
                $releaseTries++;
                $waitTime = round(microtime(true) - $lockStartTime, 6);
                $this->logError(
                    "Match release RECOVERABLE error: ID {$donation->getUuid()} got " . get_class($exception) .
                    " after {$waitTime}s on try #$releaseTries: {$exception->getMessage()}"
                );
                usleep(random_int(0, 1000000)); // Wait between 0 and 1 seconds before retrying
            } catch (Matching\TerminalLockException $exception) {
                $this->logError(
                    'Match release FINAL error: ID ' . $donation->getUuid() . ' got ' . get_class($exception) .
                    " after {$waitTime}s on try #$releaseTries: {$exception->getMessage()}"
                );
                throw $exception; // Re-throw exception after logging the details if not recoverable
            }
        }

        if (!$releaseDone) {
            $this->logger->error(
                "Match release FINAL error: ID {$donation->getUuid()} failed releasing after $releaseTries tries"
            );

            throw new DomainLockContentionException();
        }

        $this->logInfo("Taking from ID {$donation->getUuid()} released match funds totalling {$totalAmountReleased}");
        $this->logInfo('Deallocation took ' . round($lockEndTime - $lockStartTime, 6) . ' seconds');
    }

    /**
     * @return Donation[]
     */
    public function findWithExpiredMatching(): array
    {
        $cutoff = (new DateTime('now'))->sub(new \DateInterval("PT{$this->expirySeconds}S"));
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('d')
            ->from(Donation::class, 'd')
            ->leftJoin('d.fundingWithdrawals', 'fw')
            ->where('d.donationStatus = :expireWithStatus')
            ->andWhere('d.createdAt < :expireBefore')
            ->groupBy('d')
            ->having('COUNT(fw) > 0')
            ->setParameter('expireWithStatus', 'Pending')
            ->setParameter('expireBefore', $cutoff);

        return $qb->getQuery()->getResult();
    }

    /**
     * @param mixed $campaignRepository
     */
    public function setCampaignRepository($campaignRepository): void
    {
        $this->campaignRepository = $campaignRepository;
    }

    /**
     * @param FundRepository $fundRepository
     */
    public function setFundRepository(FundRepository $fundRepository): void
    {
        $this->fundRepository = $fundRepository;
    }

    /**
     * Attempt an allocation of funds. For use inside a transaction as a self-contained unit that can be rolled back
     * and retried.
     *
     * @param Donation $donation
     * @param CampaignFunding[] $fundings   Fundings likely to have funds available. To be re-queried with a
     *                                      pessimistic write lock before allocation.
     * @return FundingWithdrawal[]
     */
    private function safelyAllocateFunds(Donation $donation, array $fundings): array
    {
        $amountLeftToMatch = $donation->getAmount();
        $currentFundingIndex = 0;
        /** @var FundingWithdrawal[] $newWithdrawals Track these to persist outside the lock window, to keep it short */
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

            $this->matchingAdapter->subtractAmount($funding, $amountToAllocateNow);

            $amountLeftToMatch = bcsub($amountLeftToMatch, $amountToAllocateNow, 2);

            $withdrawal = new FundingWithdrawal();
            $withdrawal->setDonation($donation);
            $withdrawal->setCampaignFunding($funding);
            $withdrawal->setAmount($amountToAllocateNow);
            $newWithdrawals[] = $withdrawal;
            $currentFundingIndex++;

            $this->logInfo("Successfully withdrew $amountToAllocateNow from funding {$funding->getId()}");
        }

        $this->queueForPersist($donation);

        return $newWithdrawals;
    }

    private function queueForPersist(Donation $donation): void
    {
        $this->queuedForPersist[] = $donation;
    }

    private function persistQueuedDonations(): void
    {
        if (count($this->queuedForPersist) === 0) {
            return;
        }

        foreach ($this->queuedForPersist as $donation) {
            $this->getEntityManager()->persist($donation);
        }
    }

    private function flushResiliently(): void
    {
        $done = false;
        $tries = 0;

        while (!$done && $tries++ < $this->maxDoctrineDeadlockRetries) {
            try {
                $this->getEntityManager()->flush();
                $done = true;
            } catch (RetryableException $exception) {
                $this->logInfo(
                    "Doctrine flush got a retryable error on attempt #$tries - " .
                    get_class($exception) . " {$exception->getMessage()}"
                );
                usleep(random_int(0, 200000)); // Wait between 0 and 0.2 seconds before retrying
                // Carry on to next loop cycle if retryable, unless we're out of tries
            } // Any other exception is left uncaught
        }

        if (!$done) {
            // Because this isn't real-time data this is usually not absolutely critical; we can log but not bail out.
            $this->logError("Doctrine could not flush after $tries tries");
        }
    }
}
