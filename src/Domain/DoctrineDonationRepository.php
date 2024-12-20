<?php

declare(strict_types=1);

namespace MatchBot\Domain;

use DateTime;
use Doctrine\Common\Cache\CacheProvider;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use GuzzleHttp\Exception\ClientException;
use MatchBot\Application\Assertion;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching;
use MatchBot\Application\Messenger\AbstractStateChanged;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Client\BadRequestException;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\DomainException\MissingTransactionId;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @template-extends SalesforceWriteProxyRepository<Donation, \MatchBot\Client\Donation>
 * @psalm-suppress MissingConstructor Doctrine get repo DI isn't very friendly to custom constructors.
 */
class DoctrineDonationRepository extends SalesforceWriteProxyRepository implements DonationRepository
{
    /** Maximum of each type of pending object to process */
    private const int MAX_PER_BULK_PUSH = 5_000;

    private const int MAX_SALEFORCE_FIELD_UPDATE_TRIES = 3;

    private CampaignRepository $campaignRepository;
    private FundRepository $fundRepository;
    /**
     * If changing the value of EXPIRY_SECONDS make sure to update environment.reservationMinutes to match in
     * donate-frontend (or consider making frontend use expiration dates generated by matchbot)
     *
     * @link https://github.com/thebiggive/donate-frontend/blob/8e689db34fb747d0b2fd15378543649a5c34074e/src/environments/environment.production.ts
     */
    private const int EXPIRY_SECONDS = 32 * 60; // 32 minutes: 30 min official timed window plus 2 mins grace.

    private Matching\Adapter $matchingAdapter;

    public function setMatchingAdapter(Matching\Adapter $adapter): void
    {
        $this->matchingAdapter = $adapter;
    }

    public function doCreate(AbstractStateChanged $changeMessage): void
    {
        $this->upsert($changeMessage);
    }

    public function doUpdate(AbstractStateChanged $changeMessage): void
    {
        $this->upsert($changeMessage);
    }

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

    public function allocateMatchFunds(Donation $donation): string
    {
        // We look up matching withdrawals to allow for the case where retrospective matching was required
        // and the donation is not new, and *some* (or full) matching already occurred. The collection of withdrawals
        // is most often empty (for new donations) so this will frequently be 0.00.
        $amountMatchedAtStart = $donation->getFundingWithdrawalTotal();

        $lockStartTime = 0; // dummy value, should always be overwritten before usage.
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

        $this->getEntityManager()->flush(); // Flush `$newWithdrawals` if any.

        $this->logInfo('ID ' . $donation->getUuid() . ' allocated new match funds totalling ' . $amountNewlyMatched);
        $this->logInfo('Allocation took ' . round($lockEndTime - $lockStartTime, 6) . ' seconds');

        return $amountNewlyMatched;
    }

    public function releaseMatchFunds(Donation $donation): void
    {
        $startTime = microtime(true);
        try {
            $totalAmountReleased = $this->matchingAdapter->releaseAllFundsForDonation($donation);
            $this->getEntityManager()->flush();
            $endTime = microtime(true);

            try {
                $this->removeAllFundingWithdrawalsForDonation($donation);
            } catch (DBALException $exception) {
                $this->logError('Doctrine could not remove withdrawals after maximum tries');
            }
        } catch (Matching\TerminalLockException $exception) {
            $waitTime = round(microtime(true) - $startTime, 6);
            $this->logError(
                'Match release error: ID ' . $donation->getUuid() . ' got ' . get_class($exception) .
                " after {$waitTime}s: {$exception->getMessage()}"
            );
            throw $exception; // Re-throw exception after logging the details if not recoverable
        }

        $this->logInfo("Taking from ID {$donation->getUuid()} released match funds totalling {$totalAmountReleased}");
        $this->logInfo('Deallocation took ' . round($endTime - $startTime, 6) . ' seconds');
    }

    public function findWithExpiredMatching(\DateTimeImmutable $now): array
    {
        $cutoff = $now->sub(new \DateInterval('PT' . self::EXPIRY_SECONDS . 'S'));

        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('d.uuid')
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
        /** @var list<array{uuid: UuidInterface}> $rows */
        $rows = $qb->getQuery()
            ->disableResultCache()
            ->getResult();

        return array_map(static fn(array $row): UuidInterface => $row['uuid'], $rows);
    }

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

        /** @var Donation[] $result */
        $result = $qb->getQuery()->getResult();
        return $result;
    }

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
        /** @var Donation[] $result */
        $result = $qb->getQuery()
            ->disableResultCache()
            ->getResult();
        return $result;
    }


    /**
     * @psalm-suppress MixedReturnTypeCoercion
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
        $result = $qb->getQuery()
            ->disableResultCache()
            ->getResult();

        return $result;
    }

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

    public function getRecentHighVolumeCompletionRatio(\DateTimeImmutable $nowish): ?float
    {
        $oneMinutePrior = $nowish->sub(new \DateInterval('PT1M'));
        $sixteenMinutesPrior = $nowish->sub(new \DateInterval('PT16M'));

        $query = $this->getEntityManager()->createQuery(<<<'DQL'
            SELECT
            COUNT(d.id) as donationCount,
            SUM(CASE WHEN d.donationStatus IN (:completeStatuses) THEN 1 ELSE 0 END) as completeCount
            FROM MatchBot\Domain\Donation d
            LEFT JOIN d.fundingWithdrawals fw
            WHERE d.createdAt >= :start
            AND d.createdAt < :end
            HAVING SUM(fw.amount) > 0
        DQL
        );
        $query->setParameter('start', $sixteenMinutesPrior);
        $query->setParameter('end', $oneMinutePrior);
        $query->setParameter(
            'completeStatuses',
            array_map(static fn(DonationStatus $s) => $s->value, DonationStatus::SUCCESS_STATUSES),
        );

        /**
         * @var array{donationCount: int, completeCount: int}|null $result
         */
        $result = $query->getOneOrNullResult(Query::HYDRATE_ARRAY);

        if ($result === null || $result['donationCount'] < 20) {
            return null;
        }

        return (float) $result['completeCount'] / $result['donationCount'];
    }

    public function countDonationsCreatedInMinuteTo(\DateTimeImmutable $end): int
    {
        $oneMinutePrior = $end->sub(new \DateInterval('PT1M'));
        $query = $this->getEntityManager()->createQuery(<<<'DQL'
            SELECT COUNT(d.id)
            FROM MatchBot\Domain\Donation d
            WHERE d.createdAt >= :start
            AND d.createdAt < :end
        DQL
        )
            ->setParameter('start', $oneMinutePrior)
            ->setParameter('end', $end);

        return (int) $query->getSingleScalarResult();
    }

    public function countDonationsCollectedInMinuteTo(\DateTimeImmutable $end): int
    {
        $oneMinutePrior = $end->sub(new \DateInterval('PT1M'));
        $query = $this->getEntityManager()->createQuery(<<<'DQL'
            SELECT COUNT(d.id)
            FROM MatchBot\Domain\Donation d
            WHERE d.collectedAt >= :start
            AND d.collectedAt < :end
        DQL
        )
            ->setParameter('start', $oneMinutePrior)
            ->setParameter('end', $end);

        return (int) $query->getSingleScalarResult();
    }

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
     * Attempt an allocation of funds. For use inside a transaction as a self-contained unit that can be rolled back
     * and retried.
     *
     * @param Donation $donation
     * @param CampaignFunding[] $fundings   Fundings likely to have funds available. To be re-queried with a
     *                                      pessimistic write lock before allocation.
     *
     * @param numeric-string $amountMatchedAtStart Amount of match funds already allocated to the donation when we
     *                                              started.
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

            $newTotal = '[new total not defined]';
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
                $withdrawal = new FundingWithdrawal($funding);
                $withdrawal->setDonation($donation);
                $withdrawal->setAmount($amountAllocated);
                $newWithdrawals[] = $withdrawal;
                $this->logInfo("Successfully withdrew $amountAllocated from funding {$funding->getId()}");
                $this->logInfo("New fund total for {$funding->getId()}: $newTotal");
            }

            $currentFundingIndex++;
        }

        return $newWithdrawals;
    }

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

    public function removeAllFundingWithdrawalsForDonation(Donation $donation): void
    {
        $this->getEntityManager()->wrapInTransaction(function () use ($donation) {
            foreach ($donation->getFundingWithdrawals() as $fundingWithdrawal) {
                $this->getEntityManager()->remove($fundingWithdrawal);
            }
        });
    }

    /**
     * @psalm-suppress PossiblyUnusedReturnValue Psalm bug? Value is used in \MatchBot\Application\Commands\PushDonations::doExecute
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
            // Warning for now. SF blips happen, especially in sandboxes. So we think this is bad
            // enough to track on charts to see if volumes increase lots, but not to actively alert
            // on as `.ERROR`.
            $this->logger->warning("pushSalesforcePending found $count pending items to push to SF, " .
                'suggests push via Symfony Messenger failed');

            $first3OrFewerProxies = array_slice($proxiesToCreate, 0, 3);
            $firstUUIDs = array_map(static fn(Donation $d) => $d->getUuid(), $first3OrFewerProxies);
            $this->logger->info('pushSalesforcePending sample UUIDs: ' . implode(', ', $firstUUIDs));
        }

        foreach ($proxiesToCreate as $proxy) {
            if ($proxy->getUpdatedDate() > $fiveMinutesAgo) {
                // fetching the proxy just to skip it here is a bit wasteful but the performance cost is low
                // compared to working out how to do a findBy equivalent with multiple criteria
                // (i.e. using \Doctrine\ORM\EntityRepository::matching() method)
                continue;
            }

            try {
                $newDonation = DonationUpserted::fromDonation($proxy);
            } catch (MissingTransactionId) {
                $this->logger->warning("Missing transaction id for donation {$proxy->getId()}, cannot push to SF");
                continue;
            }
            $bus->dispatch(new Envelope($newDonation));
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

    private function setSalesforceFieldsWithRetry(
        AbstractStateChanged $changeMessage,
        ?Salesforce18Id $salesforceId
    ): void {
        $tries = 0;

        // Try to safely set Salesforce ID, and other push tracking fields. If it
        // fails repeatedly, this should be safe to leave for a later update.
        // Salesforce has UUIDs so we won't lose the ability to reconcile the records.
        $uuid = $changeMessage->uuid;

        do {
            try {
                $this->setSalesforceFields($uuid, $salesforceId);
                return;
            } catch (DBALException\RetryableException $exception) {
                $tries++;
                $this->logInfo(sprintf(
                    '%s: Lock unavailable to set Salesforce fields on donation %s with Salesforce ID %s on try #%d',
                    get_class($exception),
                    $uuid,
                    $salesforceId?->value ?? 'null',
                    $tries,
                ));
            } catch (DBALException\ConnectionLost $exception) {
                // Seen only at fairly quiet times *and* before we increased DB wait_timeout from 8 hours
                // to just over workers' max lifetime of 24 hours. Should happen rarely or never with new DB config.
                $tries++;
                $this->logWarning(sprintf(
                    '%s: Connection lost while setting Salesforce fields on donation %s, try #%d',
                    get_class($exception),
                    $uuid,
                    $tries,
                ));
            }
        } while ($tries < self::MAX_SALEFORCE_FIELD_UPDATE_TRIES);

        $this->logError(
            "Failed to set Salesforce fields for donation $uuid after $tries tries"
        );
    }

    /**
     * Sets a Salesforce ID (and general status things) without its own lock and importantly without the ORM, using
     * a raw DQL `UPDATE` that should make it safe irrespective of ORM work that could also be happening on the record.
     *
     * @throws DBALException\LockWaitTimeoutException if some other transaction is holding a lock
     */
    private function setSalesforceFields(string $uuid, ?Salesforce18Id $salesforceId): void
    {
        $now = new \DateTimeImmutable('now');
        $query = $this->getEntityManager()->createQuery(
            <<<'DQL'
            UPDATE Matchbot\Domain\Donation donation
            SET
                donation.salesforceId = :salesforceId,
                donation.salesforcePushStatus = 'complete',
                donation.salesforceLastPush = :now
            WHERE donation.uuid = :uuid
            DQL
        );
        $query->setParameter('now', $now);
        $query->setParameter('salesforceId', $salesforceId?->value);
        $query->setParameter('uuid', $uuid);
        $query->execute();
    }

    private function upsert(AbstractStateChanged $changeMessage): void
    {
        Assertion::isInstanceOf($changeMessage, DonationUpserted::class);

        try {
            $salesforceDonationId = $this->getClient()->createOrUpdate($changeMessage);
        } catch (NotFoundException $ex) {
            // Thrown only for *sandbox* 404s -> quietly stop trying to push donation to a removed campaign.
            $this->logInfo(
                "Marking 404 campaign Salesforce donation {$changeMessage->uuid} as complete; " .
                'will not try to push again.'
            );
            $this->setSalesforceFieldsWithRetry($changeMessage, null);

            return;
        } catch (BadRequestException $exception) {
            $this->logError(
                "Pushing Salesforce donation {$changeMessage->uuid} got 400: {$exception->getMessage()}"
            );

            return;
        }

        $this->setSalesforceFieldsWithRetry($changeMessage, $salesforceDonationId);
    }

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

    public function findPreAuthorizedDonationsReadyToConfirm(\DateTimeImmutable $atDateTime, int $limit): array
    {
        $preAuthorized = DonationStatus::PreAuthorized->value;

        $query = $this->getEntityManager()->createQuery(<<<DQL
            SELECT donation from Matchbot\Domain\Donation donation
            WHERE donation.donationStatus = '$preAuthorized'
            AND donation.preAuthorizationDate <= :now
        DQL
        );

        $query->setParameter('now', $atDateTime);
        $query->setMaxResults($limit);

        /** @var list<Donation> $result */
        $result = $query->getResult();
        return $result;
    }

    public function maxSequenceNumberForMandate(int $mandateId): ?DonationSequenceNumber
    {
        $query = $this->getEntityManager()->createQuery(<<<DQL
            SELECT MAX(d.mandateSequenceNumber) from MatchBot\Domain\Donation d join d.mandate m
            WHERE m.id = :mandate_id 
        DQL
        );

        $query->setParameter('mandate_id', $mandateId);

        $number = $query->getOneOrNullResult(Query::HYDRATE_SINGLE_SCALAR);
        \assert(is_int($number) || is_null($number));

        if ($number === null) {
            return null;
        }

        return DonationSequenceNumber::of($number);
    }

    public function findStaleDonationFundsTips(\DateTimeImmutable $atDateTime, \DateInterval $cancelationDelay): array
    {
        $pending = DonationStatus::Pending->value;

        $query = $this->getEntityManager()->createQuery(<<<DQL
            SELECT donation.uuid from Matchbot\Domain\Donation donation join donation.campaign c
            WHERE donation.donationStatus = '$pending'
            AND donation.paymentMethodType = 'customer_balance'
            AND c.name = 'Big Give General Donations'
            AND donation.createdAt < :latestCreationDate
        DQL
        );

        $query->setParameter('latestCreationDate', $atDateTime->sub($cancelationDelay));
        $query->setMaxResults(100);

        /** @var list<array{uuid: UuidInterface}> $result */
        $result = $query->getResult();

        return array_map(static fn(array $array): UuidInterface => $array['uuid'], $result);
    }

    public function findPendingByDonorCampaignAndMethod(
        string $donorStripeId,
        Salesforce18Id $campaignId,
        PaymentMethodType $paymentMethodType,
    ): array {
        $query = $this->getEntityManager()->createQuery(<<<DQL
            SELECT donation.uuid from Matchbot\Domain\Donation donation
            INNER JOIN donation.campaign campaign
            WHERE donation.donationStatus = :donationStatus
            AND donation.pspCustomerId = :donorStripeId
            AND campaign.salesforceId = :campaignId
            AND donation.paymentMethodType = :paymentMethodType
        DQL);
        $query->setParameter('donationStatus', DonationStatus::Pending->value);
        $query->setParameter('donorStripeId', $donorStripeId);
        $query->setParameter('campaignId', $campaignId->value);
        $query->setParameter('paymentMethodType', $paymentMethodType->value);

        /** @var list<array{uuid: UuidInterface}> $result */
        $result = $query->getResult();

        return array_map(static fn(array $row) => $row['uuid'], $result);
    }

    public function findAndLockOneByUUID(UuidInterface $donationId): ?Donation
    {
        return $this->findAndLockOneBy(['uuid' => $donationId->toString()]);
    }
}
