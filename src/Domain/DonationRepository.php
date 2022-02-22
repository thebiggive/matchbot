<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\DBAL\Exception as DBALException;
use GuzzleHttp\Exception\ClientException;
use MatchBot\Application\Fees\Calculator;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching;
use MatchBot\Client\BadRequestException;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\DomainException\DomainLockContentionException;
use Ramsey\Uuid\Doctrine\UuidGenerator;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\LockFactory;

class DonationRepository extends SalesforceWriteProxyRepository
{
    private CampaignRepository $campaignRepository;
    private FundRepository $fundRepository;
    private LockFactory $lockFactory;
    private int $expirySeconds = 17 * 60; // 17 minutes: 15 min official timed window plus 2 mins grace.
    /** @var int When using a locking matching adapter, maximum number of tries for real-time operations */
    private int $maxLockTries = 5;
    private Matching\Adapter $matchingAdapter;
    /** @var Donation[] Tracks donations to persist outside the time-critical transaction / lock window */
    private array $queuedForPersist;
    private array $settings;

    public function setMatchingAdapter(Matching\Adapter $adapter): void
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
        } catch (NotFoundException $ex) {
            // Thrown only for *sandbox* 404s -> quietly stop trying to push donation to a removed campaign.
            $this->logInfo(
                "Marking Salesforce donation {$donation->getId()} as campaign removed; will not try to push again."
            );
            $donation->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_REMOVED);
            $this->getEntityManager()->persist($donation);

            return true; // Report 'success' for simpler summaries and spotting of real errors.
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
        try {
            if ($donation->isNew()) {
                // A new status but an existing Salesforce ID suggests pushes might have ended up out
                // of order due to race conditions pushing to Salesforce, variable and quite slow
                // Salesforce performance characteristics, and both client (this) & server (SF) apps being
                // multi-threaded. The safest thing is not to push a Pending donation to Salesforce a 2nd
                // time, and just leave updates later in the process to get additional data there. As
                // far as calling processes and retry logic goes, this should act like a[nother] successful
                // push.
                $this->logInfo(sprintf(
                    'Skipping possible re-push of new-status donation %d, UUID %s, Salesforce ID %s',
                    $donation->getId(),
                    $donation->getUuid(),
                    $donation->getSalesforceId(),
                ));
                return true;
            }

            $result = $this->getClient()->put($donation);
        } catch (NotFoundException $ex) {
            // Thrown only for *sandbox* 404s -> quietly stop trying to push the removed donation.
            $this->logInfo(
                "Marking old Salesforce donation {$donation->getId()} as removed; will not try to push again."
            );
            $donation->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_REMOVED);
            $this->getEntityManager()->persist($donation);

            return true; // Report 'success' for simpler summaries and spotting of real errors.
        }

        return $result;
    }

    /**
     * @param DonationCreate $donationData
     * @return Donation
     * @throws \UnexpectedValueException if inputs invalid, including projectId being unrecognised
     */
    public function buildFromApiRequest(DonationCreate $donationData): Donation
    {
        if (empty($donationData->projectId)) {
            throw new \UnexpectedValueException('Required field "projectId" not set');
        }

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
                $this->logError("Pull error for campaign ID {$donationData->projectId}: {$exception->getMessage()}");
                throw new \UnexpectedValueException('Campaign does not exist');
            }
            $this->fundRepository->pullForCampaign($campaign);

            $this->getEntityManager()->flush();

            // Because this case of campaigns being set up individually is relatively rare,
            // it is the one place outside of `UpdateCampaigns` where we clear the whole
            // result cache. It's currently the only user-invoked or single item place where
            // we do so.
            /** @var CacheProvider $cacheDriver */
            $cacheDriver = $this->getEntityManager()->getConfiguration()->getResultCacheImpl();
            $cacheDriver->deleteAll();
        }

        if (empty($donationData->currencyCode)) {
            $donationData->currencyCode = 'GBP';
        }

        if ($donationData->currencyCode !== $campaign->getCurrencyCode()) {
            throw new \UnexpectedValueException(sprintf(
                'Currency %s is invalid for campaign',
                $donationData->currencyCode,
            ));
        }

        $donation = new Donation();
        $donation->setPsp($donationData->psp);
        $donation->setDonationStatus('Pending');
        $donation->setUuid((new UuidGenerator())->generate($this->getEntityManager(), $donation));
        $donation->setCampaign($campaign); // Charity & match expectation determined implicitly from this
        $donation->setAmount((string) $donationData->donationAmount);
        $donation->setCurrencyCode($donationData->currencyCode);
        $donation->setGiftAid($donationData->giftAid);
        $donation->setCharityComms($donationData->optInCharityEmail);
        $donation->setChampionComms($donationData->optInChampionEmail);
        $donation->setTbgComms($donationData->optInTbgEmail);

        if (!empty($donationData->countryCode)) {
            $donation->setDonorCountryCode($donationData->countryCode);
        }

        if (isset($donationData->feeCoverAmount)) {
            $donation->setFeeCoverAmount((string) $donationData->feeCoverAmount);
        }

        if (isset($donationData->tipAmount)) {
            $donation->setTipAmount((string) $donationData->tipAmount);
        }

        $donation = $this->deriveFees($donation);

        return $donation;
    }

    /**
     * Create all funding allocations, with `FundingWithdrawal` links to this donation, and safely update the funds'
     * available amount figures.
     *
     * @param Donation $donation
     * @return string Total amount of matching *newly* allocated
     * @see CampaignFundingRepository::getAvailableFundings() for lock acquisition detail
     */
    public function allocateMatchFunds(Donation $donation): string
    {
        $allocationDone = false;
        $allocationTries = 0;
        // We look up matching withdrawals to allow for the case where retrospective matching was required
        // and the donation is not new, and *some* (or full) matching already occurred. The collection of withdrawals
        // is most often empty (for new donations) so this will frequently be 0.00.
        $amountMatchedAtStart = $donation->getFundingWithdrawalTotal();

        while (!$allocationDone && $allocationTries < $this->maxLockTries) {
            try {
                // We need write-ready locks for `CampaignFunding`s but also to keep the time we have them as short
                // as possible, so get the prelimary list without a lock, before the transaction.

                // Get these without a lock initially
                $likelyAvailableFunds = $this->getEntityManager()
                    ->getRepository(CampaignFunding::class)
                    ->getAvailableFundings($donation->getCampaign());

                foreach ($likelyAvailableFunds as $funding) {
                    if ($funding->getCurrencyCode() !== $donation->getCurrencyCode()) {
                        throw new \UnexpectedValueException('Currency mismatch');
                    }
                }

                $lockStartTime = microtime(true);
                $newWithdrawals = $this->matchingAdapter->runTransactionally(
                    function () use ($donation, $likelyAvailableFunds, $amountMatchedAtStart) {
                        return $this->safelyAllocateFunds($donation, $likelyAvailableFunds, $amountMatchedAtStart);
                    }
                );
                $lockEndTime = microtime(true);

                $allocationDone = true;
                $this->persistQueuedDonations();

                // We end the transaction prior to inserting the funding withdrawal records, to keep the lock time
                // short. These are new entities, so except in a system crash the withdrawal totals will almost
                // immediately match the amount deducted from the fund.
                $amountNewlyMatched = '0.0';

                try {
                    $amountNewlyMatched = $this->getEntityManager()->transactional(
                        function () use ($newWithdrawals, $donation, $amountNewlyMatched) {
                            foreach ($newWithdrawals as $newWithdrawal) {
                                $this->getEntityManager()->persist($newWithdrawal);
                                $donation->addFundingWithdrawal($newWithdrawal);
                                $amountNewlyMatched = bcadd($amountNewlyMatched, $newWithdrawal->getAmount(), 2);
                            }

                            return $amountNewlyMatched;
                        }
                    );
                } catch (DBALException $exception) {
                    $this->logError('Doctrine could not update donation/withdrawals after maximum tries');
                }
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

        $this->logInfo('ID ' . $donation->getUuid() . ' allocated new match funds totalling ' . $amountNewlyMatched);

        // Monitor allocation times so we can get a sense of how risky the locking behaviour is with different DB sizes
        $this->logInfo('Allocation took ' . round($lockEndTime - $lockStartTime, 6) . ' seconds');

        return $amountNewlyMatched;
    }

    /**
     * Internally this method uses Doctrine transactionally to ensure the database updates are
     * self-consistent. But it also first acquires an exclusive lock on the fund release process
     * for the specific donation using the Symfony Lock library. If another thread is already
     * releasing funds for the same donation, we log this fact but consider it safe to return
     * without releasing any funds.
     *
     * @param Donation $donation
     * @throws DomainLockContentionException
     * @throws Matching\TerminalLockException
     */
    public function releaseMatchFunds(Donation $donation): void
    {
        // Release match funds only having acquired a lock to ensure another thread
        // isn't doing so. We've seen rare issues (MAT-143, MAT-169) during CC20 and
        // into 2021 where the same valid Cancel request was sent twice in rapid succession
        // and funds were double-released.
        //
        // It seems like the relative speed of the Redis operations compared to the rest of
        // the request/response cycle (external network etc.) makes calling `isAcquired()`
        // fairly pointless. In practice it was looking like we'd more often have that return
        // false but subsequent lock acquisition fail in the rapid double-request cases we
        // were able to observe. This is why we now go straight to trying to `acquire()`. If
        // this returns false, we are in the contested lock case and know to drop this attempt.

        $fundsReleaseLock = $this->lockFactory->createLock("release-funds-{$donation->getUuid()}");

        try {
            $gotLock = $fundsReleaseLock->acquire(false);
        } catch (LockAcquiringException $exception) {
            // According to the method (but not the exception) docs, `LockConflictedException` is thrown only
            // "If the lock is acquired by someone else in blocking mode", and so should not be expected for
            // our use case or caught here.
            $this->logger->warning(sprintf(
                'Skipped releasing match funds for donation ID %s due to %s acquiring lock',
                $donation->getUuid(),
                get_class($exception),
            ));

            return;
        }

        if (!$gotLock) {
            $this->logger->warning(sprintf(
                'Skipped releasing match funds for donation ID %s as lock was not acquired',
                $donation->getUuid(),
            ));

            return;
        }

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
                            $newTotal = $this->matchingAdapter->addAmount($funding, $fundingWithdrawal->getAmount());
                            $totalAmountReleased = bcadd($totalAmountReleased, $fundingWithdrawal->getAmount(), 2);
                            $this->logInfo("Released {$fundingWithdrawal->getAmount()} to funding {$funding->getId()}");
                            $this->logInfo("New fund total for {$funding->getId()}: $newTotal");
                        }

                        return $totalAmountReleased;
                    }
                );
                $lockEndTime = microtime(true);
                $releaseDone = true;

                try {
                    $this->getEntityManager()->transactional(function () use ($donation) {
                        foreach ($donation->getFundingWithdrawals() as $fundingWithdrawal) {
                            $this->getEntityManager()->remove($fundingWithdrawal);
                        }
                    });
                } catch (DBALException $exception) {
                    $this->logError('Doctrine could not remove withdrawals after maximum tries');
                }
            } catch (Matching\TerminalLockException $exception) {
                $waitTime = round(microtime(true) - $lockStartTime, 6);
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

        $fundsReleaseLock->release();
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

        // As this is used by the only regular task working with donations,
        // `ExpireMatchFunds`, it makes more sense to opt it out of query caching
        // here rather than take the performance hit of a full query cache clear
        // after every single persisted donation.
        return $qb->getQuery()
            ->disableResultCache()
            ->getResult();
    }

    /**
     * @return Donation[]
     */
    public function findReadyToClaimGiftAid(bool $pilotCharitiesOnly, bool $withResends): array
    {
        $giftAidSettings = $this->settings['gift_aid'];

        if ($pilotCharitiesOnly && empty($giftAidSettings['pilot_salesforce_ids'])) {
            throw new \LogicException('Cannot use pilot charity claim mode without env var');
        }

        if (!$pilotCharitiesOnly && !empty($giftAidSettings['pilot_salesforce_ids'])) {
            throw new \LogicException('Cannot use global charity claim mode with pilot env var set');
        }

        if ($withResends && getenv('APP_ENV') === 'production') {
            throw new \LogicException('Cannot re-send live donations');
        }

        $cutoff = (new DateTime('now'))->sub(new \DateInterval('P14D'));

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('d')
            ->from(Donation::class, 'd')
            ->innerJoin('d.campaign', 'campaign')
            ->innerJoin('campaign.charity', 'charity')
            ->where('d.donationStatus = :claimGiftAidWithStatus')
            ->andWhere('d.giftAid = TRUE')
            ->andWhere('d.tbgShouldProcessGiftAid = TRUE')
            ->andWhere('charity.hmrcReferenceNumber IS NOT NULL')
            ->andWhere('d.collectedAt < :claimGiftAidForDonationsBefore')
            ->orderBy('charity.id', 'ASC') // group donations for the same charity together in batches
            ->addOrderBy('d.collectedAt', 'ASC')
            ->setParameter('claimGiftAidWithStatus', 'Paid')
            ->setParameter('claimGiftAidForDonationsBefore', $cutoff);

        if ($pilotCharitiesOnly) {
            /** @var string[] $salesforceIds */
            $qb = $qb->andWhere('charity.salesforceId IN (:pilotSalesforceIds)')
                ->setParameter('pilotSalesforceIds', $giftAidSettings['pilot_salesforce_ids']);
        }

        if (!$withResends) {
            $qb = $qb->andWhere('d.tbgGiftAidRequestQueuedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Donation[]
     */
    public function findRecentNotFullyMatchedToMatchCampaigns(DateTime $sinceDate): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('d')
            ->from(Donation::class, 'd')
            ->join('d.campaign', 'c')
            ->leftJoin('d.fundingWithdrawals', 'fw')
            ->where('d.donationStatus IN (:completeStatuses)')
            ->andWhere('c.isMatched = :campaignMatched')
            ->andWhere('d.createdAt >= :checkAfter')
            ->groupBy('d')
            ->having('(SUM(fw.amount) IS NULL OR SUM(fw.amount) < d.amount)') // No withdrawals *or* less than donation
            ->orderBy('d.createdAt', 'ASC')
            ->setParameter('completeStatuses', Donation::getSuccessStatuses())
            ->setParameter('campaignMatched', true)
            ->setParameter('checkAfter', $sinceDate);

        // Result caching rationale as per `findWithExpiredMatching()`, except this is
        // currently used only in the rarer case of manually invoking
        // `RetrospectivelyMatch`.
        return $qb->getQuery()
            ->disableResultCache()
            ->getResult();
    }

    /**
     * @param string[]  $transferIds
     * @return Donation[]
     */
    public function findWithTransferIdInArray(array $transferIds): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('d')
            ->from(Donation::class, 'd')
            ->where('d.transferId IN (:transferIds)')
            ->setParameter('transferIds', $transferIds);

        return $qb->getQuery()->getResult();
    }

    /**
     * Give up on pushing Cancelled donations to Salesforce after a few minutes. For example,
     * this was needed after CC21 for a last minute donation that could not be persisted in
     * Salesforce because the campaign close date had passed before it reached SF.
     *
     * @return int  Number of donations updated to 'not-sent'.
     */
    public function abandonOldCancelled(): int
    {
        $twentyMinsAgo = (new DateTime('now'))
            ->sub(new \DateInterval('PT20M'));
        $pendingSFPushStatuses = [
            SalesforceWriteProxy::PUSH_STATUS_PENDING_ADDITIONAL_UPDATE,
            SalesforceWriteProxy::PUSH_STATUS_PENDING_CREATE,
            SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE,
        ];

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('d')
            ->from(Donation::class, 'd')
            ->where('d.donationStatus = :cancelledStatus')
            ->andWhere('d.salesforcePushStatus IN (:pendingSFPushStatuses)')
            ->andWhere('d.createdAt < :twentyMinsAgo')
            ->orderBy('d.createdAt', 'ASC')
            ->setParameter('cancelledStatus', 'Cancelled')
            ->setParameter('pendingSFPushStatuses', $pendingSFPushStatuses)
            ->setParameter('twentyMinsAgo', $twentyMinsAgo);

        /** @var Donation[] $donations */
        $donations = $qb->getQuery()->getResult();
        if (count($donations) > 0) {
            foreach ($donations as $donation) {
                $donation->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_NOT_SENT);
                $this->getEntityManager()->persist($donation);
            }

            $this->getEntityManager()->flush();
        }

        return count($donations);
    }

    public function deriveFees(Donation $donation, ?string $cardBrand = null, ?string $cardCountry = null): Donation
    {
        $incursGiftAidFee = (
            $donation->hasGiftAid() &&
            ($donation->getPsp() === 'enthuse' || $donation->hasTbgShouldProcessGiftAid())
        );

        $structure = new Calculator(
            $this->settings,
            $donation->getPsp(),
            $cardBrand,
            $cardCountry,
            $donation->getAmount(),
            $donation->getCurrencyCode(),
            $incursGiftAidFee,
            $donation->getCampaign()->getFeePercentage(),
        );
        $donation->setCharityFee($structure->getCoreFee());
        $donation->setCharityFeeVat($structure->getFeeVat());

        return $donation;
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
     * @param LockFactory $lockFactory
     */
    public function setLockFactory(LockFactory $lockFactory): void
    {
        $this->lockFactory = $lockFactory;
    }

    /**
     * @param array $settings
     */
    public function setSettings(array $settings): void
    {
        $this->settings = $settings;
    }

    /**
     * Attempt an allocation of funds. For use inside a transaction as a self-contained unit that can be rolled back
     * and retried.
     *
     * @param Donation $donation
     * @param CampaignFunding[] $fundings   Fundings likely to have funds available. To be re-queried with a
     *                                      pessimistic write lock before allocation.
     * @param string                        Amount of match funds already allocated to the donation when we started.
     * @return FundingWithdrawal[]
     */
    private function safelyAllocateFunds(Donation $donation, array $fundings, string $amountMatchedAtStart): array
    {
        $amountLeftToMatch = bcsub($donation->getAmount(), $amountMatchedAtStart, 2);
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

            try {
                $newTotal = $this->matchingAdapter->subtractAmount($funding, $amountToAllocateNow);
                $amountAllocated = $amountToAllocateNow; // If no exception thrown
            } catch (Matching\LessThanRequestedAllocatedException $exception) {
                $amountAllocated = $exception->getAmountAllocated();
                $this->logInfo(
                    "Amount available from funding {$funding->getId()} changed: - got $amountAllocated " .
                    "of requested $amountToAllocateNow"
                );
            }

            $amountLeftToMatch = bcsub($amountLeftToMatch, $amountAllocated, 2);

            if (bccomp($amountAllocated, '0.00', 2) === 1) {
                $withdrawal = new FundingWithdrawal();
                $withdrawal->setDonation($donation);
                $withdrawal->setCampaignFunding($funding);
                $withdrawal->setAmount($amountAllocated);
                $newWithdrawals[] = $withdrawal;
                $this->logInfo("Successfully withdrew $amountAllocated from funding {$funding->getId()}");
                $this->logInfo("New fund total for {$funding->getId()}: $newTotal");
            }

            $currentFundingIndex++;
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
}
