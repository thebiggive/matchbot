<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\LockMode;
use GuzzleHttp\Exception\ClientException;
use MatchBot\Application\Assertion;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching;
use MatchBot\Application\Messenger\AbstractStateChanged;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Client\BadRequestException;
use MatchBot\Client\NotFoundException;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @template-extends SalesforceWriteProxyRepository<Donation, \MatchBot\Client\Donation>
 * @psalm-suppress MissingConstructor Doctrine get repo DI isn't very friendly to custom constructors.
 */
class DonationRepository extends SalesforceWriteProxyRepository
{
    /** Maximum of each type of pending object to process */
    private const MAX_PER_BULK_PUSH = 5_000;

    private CampaignRepository $campaignRepository;
    private FundRepository $fundRepository;
    private LockFactory $lockFactory;

    /**
     * If changing the value of EXPIRY_SECONDS make sure to update environment.reservationMinutes to match in
     * donate-frontend (or consider making frontend use expiration dates generated by matchbot)
     *
     * @link https://github.com/thebiggive/donate-frontend/blob/8e689db34fb747d0b2fd15378543649a5c34074e/src/environments/environment.production.ts
     */
    private const EXPIRY_SECONDS = 32 * 60; // 32 minutes: 30 min official timed window plus 2 mins grace.

    private Matching\Adapter $matchingAdapter;
    /** @var Donation[] Tracks donations to persist outside the time-critical transaction / lock window */
    private array $queuedForPersist;

    public function setMatchingAdapter(Matching\Adapter $adapter): void
    {
        $this->matchingAdapter = $adapter;
    }

    public function doCreate(AbstractStateChanged $changeMessage): bool
    {
        Assertion::isInstanceOf($changeMessage, DonationUpserted::class);

        try {
            $salesforceDonationId = $this->getClient()->createOrUpdate($changeMessage);
            $this->setSalesforceIdIfNeeded($changeMessage, $salesforceDonationId);
        } catch (NotFoundException $ex) {
            // Thrown only for *sandbox* 404s -> quietly stop trying to push donation to a removed campaign.
            $this->logInfo(
                "Marking Salesforce donation {$changeMessage->uuid} as campaign removed; will not try to push again."
            );

            return false;
        } catch (BadRequestException $exception) {
            return false;
        }

        return true;
    }

    public function doUpdate(AbstractStateChanged $changeMessage): bool
    {
        Assertion::isInstanceOf($changeMessage, DonationUpserted::class);

        try {
            $salesforceDonationId = $this->getClient()->createOrUpdate($changeMessage);
        } catch (NotFoundException $ex) {
            // Thrown only for *sandbox* 404s -> quietly stop trying to push the removed donation.
            $this->logInfo(
                "Marking 404 campaign Salesforce donation {$changeMessage->uuid} as complete; " .
                'will not try to push again.'
            );

            return false;
        }

        $this->setSalesforceIdIfNeeded($changeMessage, $salesforceDonationId);

        return true;
    }

    /**
     * @param DonationCreate $donationData
     * @return Donation
     * @throws \UnexpectedValueException if inputs invalid, including projectId being unrecognised
     */
    public function buildFromApiRequest(DonationCreate $donationData): Donation
    {
        if (!in_array($donationData->psp, ['stripe'], true)) {
            throw new \UnexpectedValueException(sprintf(
                'PSP %s is invalid',
                $donationData->psp,
            ));
        }

        $campaign = $this->campaignRepository->findOneBy(['salesforceId' => $donationData->projectId->value]);

        if (!$campaign) {
            // Fetch data for as-yet-unknown campaigns on-demand
            $this->logInfo("Loading unknown campaign ID {$donationData->projectId} on-demand");
            try {
                $campaign = $this->campaignRepository->pullNewFromSf($donationData->projectId);
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

        if ($donationData->currencyCode !== $campaign->getCurrencyCode()) {
            throw new \UnexpectedValueException(sprintf(
                'Currency %s is invalid for campaign',
                $donationData->currencyCode,
            ));
        }

        $donation = Donation::fromApiModel($donationData, $campaign);
        $donation->deriveFees(null, null);

        return $donation;
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

        try {
            /** @var list<CampaignFunding> $likelyAvailableFunds */
            $likelyAvailableFunds = $this->getEntityManager()
                ->getRepository(CampaignFunding::class)
                ->getAvailableFundings($donation->getCampaign());

            foreach ($likelyAvailableFunds as $funding) {
                if ($funding->getCurrencyCode() !== $donation->getCurrencyCode()) {
                    throw new \UnexpectedValueException('Currency mismatch');
                }
            }

            $lockStartTime = microtime(true);
            $newWithdrawals = $this->safelyAllocateFunds($donation, $likelyAvailableFunds, $amountMatchedAtStart);
            $lockEndTime = microtime(true);

            $this->persistQueuedDonations();
        } catch (Matching\TerminalLockException $exception) {
            $waitTime = round(microtime(true) - $lockStartTime, 6);
            $this->logError(
                "Match allocate error: ID {$donation->getUuid()} got " . get_class($exception) .
                " after {$waitTime}s: {$exception->getMessage()}"
            );
            throw $exception; // Re-throw exception after logging the details if not recoverable
        }

        // We release the allocation lock prior to inserting the funding withdrawal records, to keep the lock
        // time short. These are new entities, so except in a system crash the withdrawal totals will almost
        // immediately match the amount deducted from the fund.
        $amountNewlyMatched = '0.0';

        foreach ($newWithdrawals as $newWithdrawal) {
            $this->getEntityManager()->persist($newWithdrawal);
            $donation->addFundingWithdrawal($newWithdrawal);
            $newWithdrawalAmount = $newWithdrawal->getAmount();
            Assertion::numeric($newWithdrawalAmount);
            $amountNewlyMatched = bcadd($amountNewlyMatched, $newWithdrawalAmount, 2);
        }

        $this->logInfo('ID ' . $donation->getUuid() . ' allocated new match funds totalling ' . $amountNewlyMatched);
        $this->logInfo('Allocation took ' . round($lockEndTime - $lockStartTime, 6) . ' seconds');

        $this->getEntityManager()->flush();

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

        try {
            $lockStartTime = microtime(true);
            $totalAmountReleased = $this->matchingAdapter->releaseAllFundsForDonation($donation);
            $lockEndTime = microtime(true);

            try {
                $this->removeAllFundingWithdrawalsForDonation($donation);
            } catch (DBALException $exception) {
                $this->logError('Doctrine could not remove withdrawals after maximum tries');
            }
        } catch (Matching\TerminalLockException $exception) {
            $waitTime = round(microtime(true) - $lockStartTime, 6);
            $this->logError(
                'Match release error: ID ' . $donation->getUuid() . ' got ' . get_class($exception) .
                " after {$waitTime}s: {$exception->getMessage()}"
            );
            throw $exception; // Re-throw exception after logging the details if not recoverable
        }

        $this->logInfo("Taking from ID {$donation->getUuid()} released match funds totalling {$totalAmountReleased}");
        $this->logInfo('Deallocation took ' . round($lockEndTime - $lockStartTime, 6) . ' seconds');

        $fundsReleaseLock->release();
    }

    /**
     * @return Donation[]
     */
    public function findWithExpiredMatching(\DateTimeImmutable $now): array
    {
        $cutoff = $now->sub(new \DateInterval('PT' . self::EXPIRY_SECONDS . 'S'));

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('d')
            ->from(Donation::class, 'd')
            // Only select donations with 1+ FWs. We don't need any further info about the FWs.
            ->innerJoin('d.fundingWithdrawals', 'fw')
            ->where('d.donationStatus IN (:expireWithStatuses)')
            ->andWhere('d.createdAt < :expireBefore')
            ->groupBy('d.id')
            ->setParameter('expireWithStatuses', [DonationStatus::Pending->value, DonationStatus::Cancelled->value])
            ->setParameter('expireBefore', $cutoff)
        ;

        // As this is used by the only regular task working with donations,
        // `ExpireMatchFunds`, it makes more sense to opt it out of result caching
        // here rather than take the performance hit of a full query cache clear
        // after every single persisted donation.
        return $qb->getQuery()
            ->disableResultCache()
            ->getResult();
    }

    /**
     * @return Donation[]   Donations which, when considered in isolation, could have some or all of their match
     *                      funds swapped with higher priority matching (e.g. swapping out champion funds and
     *                      swapping in pledges). The caller shouldn't assume that *all* donations may be fully
     *                      swapped; typically we will choose to swap earlier-collected donations first, and it may
     *                      be that priority funds are used up before we get to the end of the list.
     */
    public function findWithMatchingWhichCouldBeReplacedWithHigherPriorityAllocation(
        \DateTimeImmutable $campaignsClosedBefore,
        \DateTimeImmutable $donationsCollectedAfter,
    ): array {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('d')
            ->from(Donation::class, 'd')
            // Only select donations with 1+ FWs (i.e. some matching).
            ->innerJoin('d.fundingWithdrawals', 'fw')
            ->innerJoin('fw.campaignFunding', 'donationCf')
            ->innerJoin('d.campaign', 'c')
            // Join CampaignFundings allocated to campaign `c` with some amount available and a lower allocationOrder
            // than the funding of `fw`.
            ->innerJoin(
                'c.campaignFundings',
                'availableCf',
                'WITH',
                'availableCf.amountAvailable > 0 AND availableCf.allocationOrder < donationCf.allocationOrder'
            )
            ->where('c.endDate < :campaignsClosedBefore')
            ->andWhere('d.donationStatus IN (:collectedStatuses)')
            ->andWhere('d.collectedAt > :donationsCollectedAfter')
            ->groupBy('d.id')
            ->orderBy('d.id')
            ->setParameter('campaignsClosedBefore', $campaignsClosedBefore)
            ->setParameter('collectedStatuses', DonationStatus::SUCCESS_STATUSES)
            ->setParameter('donationsCollectedAfter', $donationsCollectedAfter)
        ;

        // Result caching rationale as per `findWithExpiredMatching()`.
        /** @var Donation[] $donations */
        $donations = $qb->getQuery()
            ->disableResultCache()
            ->getResult();

        return $donations;
    }

    /**
     * @return Donation[]
     */
    public function findReadyToClaimGiftAid(bool $withResends): array
    {
        if ($withResends && getenv('APP_ENV') === 'production') {
            throw new \LogicException('Cannot re-send live donations');
        }

        // Stripe's weekly payout schedule uses a `weekly_anchor` of Monday and `delay_days` set to 14. However,
        // as of 5 July 2022, we see essentially undocumented behaviour such that donations on a Monday can have less
        // than 14 *full* days before they're paid. This led to discrepancies with when we could expect Gift Aid to be
        // sent for any donation collected between ~9am and midnight Mondays. To reduce future confusion, we now only
        // require a minimum 13 days from collection. Note that this condition has always been checked in concert with
        // the hard requirement that Stripe tell us the donation is Paid. Additionally, we wait for HMRC to tell
        // us we're approved as Agent – for charities new to us claiming Gift Aid, this is likely to be a couple
        // of months as of 2023.
        $cutoff = (new DateTime('now'))->sub(new \DateInterval('P13D'));

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('d')
            ->from(Donation::class, 'd')
            ->innerJoin('d.campaign', 'campaign')
            ->innerJoin('campaign.charity', 'charity')
            ->where('d.donationStatus = :claimGiftAidWithStatus')
            ->andWhere('d.giftAid = TRUE')
            ->andWhere('d.tbgShouldProcessGiftAid = TRUE')
            ->andWhere('charity.tbgApprovedToClaimGiftAid = TRUE')
            ->andWhere('charity.hmrcReferenceNumber IS NOT NULL')
            ->andWhere('d.collectedAt < :claimGiftAidForDonationsBefore')
            ->orderBy('charity.id', 'ASC') // group donations for the same charity together in batches
            ->addOrderBy('d.collectedAt', 'ASC')
            ->setParameter('claimGiftAidWithStatus', DonationStatus::Paid->value)
            ->setParameter('claimGiftAidForDonationsBefore', $cutoff);

        if (!$withResends) {
            $qb = $qb->andWhere('d.tbgGiftAidRequestQueuedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * @return Donation[]
     */
    public function findNotFullyMatchedToCampaignsWhichClosedSince(DateTime $closedSinceDate): array
    {
        $now = (new DateTime('now'));
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('d')
            ->from(Donation::class, 'd')
            ->join('d.campaign', 'c')
            ->leftJoin('d.fundingWithdrawals', 'fw')
            ->where('d.donationStatus IN (:completeStatuses)')
            ->andWhere('c.isMatched = true')
            ->andWhere('c.endDate < :now')
            ->andWhere('c.endDate > :campaignClosedSince')
            ->groupBy('d.id')
            ->having('(SUM(fw.amount) IS NULL OR SUM(fw.amount) < d.amount)') // No withdrawals *or* less than donation
            ->orderBy('d.createdAt', 'ASC')
            ->setParameter(
                'completeStatuses',
                array_map(static fn(DonationStatus $s) => $s->value, DonationStatus::SUCCESS_STATUSES),
            )
            ->setParameter('campaignClosedSince', $closedSinceDate)
            ->setParameter('now', $now);

        // Result caching rationale as per `findWithExpiredMatching()`.
        return $qb->getQuery()
            ->disableResultCache()
            ->getResult();
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
            ->andWhere('c.isMatched = true')
            ->andWhere('d.createdAt >= :checkAfter')
            ->groupBy('d.id')
            ->having('(SUM(fw.amount) IS NULL OR SUM(fw.amount) < d.amount)') // No withdrawals *or* less than donation
            ->orderBy('d.createdAt', 'ASC')
            ->setParameter(
                'completeStatuses',
                array_map(static fn(DonationStatus $s) => $s->value, DonationStatus::SUCCESS_STATUSES),
            )
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

        /** @var Donation[] $donations */
        $donations = $qb->getQuery()->getResult();

        return $donations;
    }

    /**
     * Give up on pushing Cancelled donations to Salesforce after a few minutes. For example,
     * this was needed after CC21 for a last minute donation that could not be persisted in
     * Salesforce because the campaign close date had passed before it reached SF.
     *
     * @return int  Number of donations updated to 'complete'.
     */
    public function abandonOldCancelled(): int
    {
        $twentyMinsAgo = (new DateTime('now'))
            ->sub(new \DateInterval('PT20M'));
        $pendingSFPushStatuses = [
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
            ->setParameter('cancelledStatus', DonationStatus::Cancelled->value)
            ->setParameter('pendingSFPushStatuses', $pendingSFPushStatuses)
            ->setParameter('twentyMinsAgo', $twentyMinsAgo);

        /** @var Donation[] $donations */
        $donations = $qb->getQuery()->getResult();
        if (count($donations) > 0) {
            foreach ($donations as $donation) {
                $donation->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_COMPLETE);
                $this->getEntityManager()->persist($donation);
            }

            $this->getEntityManager()->flush();
        }

        return count($donations);
    }

    public function setCampaignRepository(CampaignRepository $campaignRepository): void
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
                $newTotal = $this->matchingAdapter->subtractAmountWithoutSavingToDB($funding, $amountToAllocateNow);
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
                $withdrawal = new FundingWithdrawal($funding);
                $withdrawal->setDonation($donation);
                $withdrawal->setAmount($amountAllocated);
                $newWithdrawals[] = $withdrawal;
                $this->logInfo("Successfully withdrew $amountAllocated from funding {$funding->getId()}");
                $this->logInfo("New fund total for {$funding->getId()}: $newTotal");
            }

            $currentFundingIndex++;
        }

        $this->queueForPersist($donation);

        $this->matchingAdapter->saveFundingsToDatabase();
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

    /**
     * Locks row in DB to prevent concurrent updates. See jira MAT-260
     * Requires an open transaction to be managed by the caller.
     * @throws DBALException\LockWaitTimeoutException
     */
    public function findAndLockOneBy(array $criteria, ?array $orderBy = null): ?Donation
    {
        // We can't actually lock the row until we know the ID of the donation, so we fetch it first
        // using the criteria, and then call find once we know the ID to lock.
        $donation = $this->findOneBy($criteria, $orderBy);

        if ($donation === null) {
            return null;
        }

        $this->getEntityManager()->refresh($donation, LockMode::PESSIMISTIC_WRITE);

        return $donation;
    }

    /**
     * Normally called just as part of releaseMatchFunds which also releases the funds in Redis. But
     * used separately in case of a crash when we would need to release the funds in Redis whether or not
     * we have any FundingWithdrawals in MySQL.
     */
    public function removeAllFundingWithdrawalsForDonation(Donation $donation): void
    {
        $this->getEntityManager()->transactional(function () use ($donation) {
            foreach ($donation->getFundingWithdrawals() as $fundingWithdrawal) {
                $this->getEntityManager()->remove($fundingWithdrawal);
            }
        });
    }

    /**
     * Re-queues proxy objects to Salesforce en masse.
     *
     * By using FIFO queues and deduplicating on UUID if there are multiple consumers, we should make it unlikely
     * that Salesforce hits Donation record lock contention issues.
     *
     * @return int  Number of objects pushed
     */
    public function pushSalesforcePending(\DateTimeImmutable $now, MessageBusInterface $bus): int
    {
        // We don't want to push donations that were created or modified in the last 5 minutes,
        // to avoid collisions with other pushes.
        $fiveMinutesAgo = $now->modify('-5 minutes');

        /** @var Donation[] $proxiesToCreate */
        $proxiesToCreate = $this->findBy(
            ['salesforcePushStatus' => SalesforceWriteProxy::PUSH_STATUS_PENDING_CREATE],
            ['updatedAt' => 'ASC'],
            self::MAX_PER_BULK_PUSH,
        );

        if ($proxiesToCreate !== []) {
            $count = count($proxiesToCreate);
            $this->logger->error(
                "Found $count pending items to push to SF, suggests push via Symfony Messenger failed"
            );
        }

        foreach ($proxiesToCreate as $proxy) {
            if ($proxy->getUpdatedDate() > $fiveMinutesAgo) {
                // fetching the proxy just to skip it here is a bit wasteful but the performance cost is low
                // compared to working out how to do a findBy equivalent with multiple criteria
                // (i.e. using \Doctrine\ORM\EntityRepository::matching() method)
                continue;
            }

            $bus->dispatch(new Envelope(DonationUpserted::fromDonation($proxy)));
        }

        $proxiesToUpdate = $this->findBy(
            ['salesforcePushStatus' => SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE],
            ['updatedAt' => 'ASC'],
            self::MAX_PER_BULK_PUSH,
        );

        foreach ($proxiesToUpdate as $proxy) {
            if ($proxy->getUpdatedDate() > $fiveMinutesAgo) {
                continue;
            }

            $bus->dispatch(new Envelope(DonationUpserted::fromDonation($proxy)));
        }

        return count($proxiesToCreate) + count($proxiesToUpdate);
    }

    private function setSalesforceIdIfNeeded(AbstractStateChanged $changeMessage, Salesforce18Id $salesforceId): void
    {
        // If Salesforce ID wasn't set yet, try to safely set it. If it
        // fails, this should be safe to leave for a later update. Salesforce has UUIDs so
        // we won't lose the ability to reconcile the records.
        $uuid = $changeMessage->uuid;
        try {
            $this->safelySetSalesforceId($uuid, $salesforceId);
        } catch (DBALException\LockWaitTimeoutException $exception) {
            // Initial checks on Regression suggest that this happens semi-regularly and that recovery
            // from later calls is very possibly good enough without retrying here. So for now the level
            // is `.INFO` only, and we also need to avoid logging the 'An exception occurred...' bit of
            // the message to stop a sensitive log metric pattern from interpreting the event as an error.
            $messageWithoutPrefix = str_replace(
                'An exception occurred while executing a query: ',
                '',
                $exception->getMessage(),
            );
            $this->logInfo(sprintf(
                'Lock unavailable to give donation %s Salesforce ID %s, will leave for later: %s',
                $uuid,
                $salesforceId,
                $messageWithoutPrefix,
            ));
        }
    }

    /**
     * Sets a Salesforce ID without its own lock and importantly without the ORM, using
     * a raw DQL `UPDATE` that should make it safe irrespective of ORM work that could
     * also be happening on the record.
     *
     * @throws DBALException\LockWaitTimeoutException if some other transaction is holding a lock
     */
    private function safelySetSalesforceId(string $uuid, Salesforce18Id $salesforceId): void
    {
        $query = $this->getEntityManager()->createQuery(
            <<<'DQL'
            UPDATE Matchbot\Domain\Donation donation
            SET donation.salesforceId = :salesforceId
            WHERE donation.uuid = :uuid
            DQL
        );
        $query->setParameter('salesforceId', $salesforceId->value);
        $query->setParameter('uuid', $uuid);
        $query->execute();
    }

    /**
     * Finds all successful donations from the donor with the given stripe customer ID.
     *
     * In principle, we would probably prefer to the user ID's we've assigned to donors here
     * instead of the Stripe Customer ID, so we're less tied into stripe, but we don't have those currently in
     * the Donation table. Considering adding that column and writing a script to fill in on all old donations.
     * @return list<Donation>
     */
    public function findAllCompleteForCustomer(StripeCustomerId $stripeCustomerId): array
    {
        $query = $this->getEntityManager()->createQuery(<<<'DQL'
            SELECT donation from Matchbot\Domain\Donation donation
            WHERE donation.pspCustomerId = :pspCustomerId
            AND donation.donationStatus IN (:succcessStatus)
            ORDER BY donation.createdAt DESC
        DQL
        );

        $query->setParameter('pspCustomerId', $stripeCustomerId->stripeCustomerId);
        $query->setParameter('succcessStatus', DonationStatus::SUCCESS_STATUSES);

        /** @var list<Donation> $result */
        $result = $query->getResult();
        return $result;
    }
}
