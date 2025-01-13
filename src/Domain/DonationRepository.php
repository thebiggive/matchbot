<?php

 namespace MatchBot\Domain;

use DateTime;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching;
use MatchBot\Application\Messenger\AbstractStateChanged;
use MatchBot\Client\NotFoundException;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Includes a subset of methods from \Doctrine\Persistence\ObjectRepository<Donation>. Not adding all methods
 * to allow for easier in-memory implementation.
 */
interface DonationRepository
{
    /**
     * @param DonationCreate $donationData
     * @return Donation
     * @throws \UnexpectedValueException if inputs invalid, including projectId being unrecognised
     * @throws NotFoundException
     */public function buildFromApiRequest(DonationCreate $donationData): Donation;

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
    public function allocateMatchFunds(Donation $donation): string;

    /**
     *
     * Internally this method uses Doctrine transactionally to ensure the database updates are
     * self-consistent. But it also first acquires an exclusive lock on the fund release process
     * for the specific donation using the Symfony Lock library. If another thread is already
     * releasing funds for the same donation, we log this fact but consider it safe to return
     * without releasing any funds.
     *
     * Should mostly be thoght of as internal to `releaseMatchFundsInTransaction` - call that rather than calling
     * this directly.
     *
     * @psalm-internal MatchBot\Domain
     * @param Donation $donation
     * @throws Matching\TerminalLockException
     */
    public function releaseMatchFunds(Donation $donation): void;

    /**
     * @return UuidInterface[]
     */
    public function findWithExpiredMatching(\DateTimeImmutable $now): array;

    /**
     * @return Donation[]   Donations which, when considered in isolation, could have some or all of their match
     *                      funds swapped with higher priority matching (e.g. swapping out champion funds and
     *                      swapping in pledges). The caller shouldn't assume that *all* donations may be fully
     *                      swapped; typically we will choose to swap earlier-collected donations first, and it may
     *                      be that priority funds are used up before we get to the end of the list.
     */
    public function findWithMatchingWhichCouldBeReplacedWithHigherPriorityAllocation(\DateTimeImmutable $campaignsClosedBefore, \DateTimeImmutable $donationsCollectedAfter,): array;

    /**
     * @return Donation[]
     */
    public function findReadyToClaimGiftAid(bool $withResends): array;

    /**
     * @return Donation[]
     */
    public function findNotFullyMatchedToCampaignsWhichClosedSince(DateTime $closedSinceDate): array;

    /**
     * @return Donation[]
     */
    public function findRecentNotFullyMatchedToMatchCampaigns(DateTime $sinceDate): array;

    /**
     * @param string[]  $transferIds
     * @return Donation[]
     */
    public function findWithTransferIdInArray(array $transferIds): array;

    /**
     * Takes a now-ish input that's typically the floor of the current minute and
     * looks for donations *created* between $nowish-16 minutes and $nowish-1 minutes, with >£0 matching assigned.
     * Returns:
     * * if there are fewer than 20 such donations, null; or
     * * if there are 20+ such donations, the ratio of those which are complete.
     */
    public function getRecentHighVolumeCompletionRatio(\DateTimeImmutable $nowish): ?float;

    public function countDonationsCreatedInMinuteTo(\DateTimeImmutable $end): int;

    public function countDonationsCollectedInMinuteTo(\DateTimeImmutable $end): int;

    /**
     * Give up on pushing Cancelled donations to Salesforce after a few minutes. For example,
     * this was needed after CC21 for a last minute donation that could not be persisted in
     * Salesforce because the campaign close date had passed before it reached SF.
     *
     * @return int  Number of donations updated to 'complete'.
     */
    public function abandonOldCancelled(): int;

    public function setCampaignRepository(CampaignRepository $campaignRepository): void;

    /**
     * Locks row in DB to prevent concurrent updates. See jira MAT-260
     * Requires an open transaction to be managed by the caller.
     * @throws LockWaitTimeoutException
     */
    public function findAndLockOneBy(array $criteria, ?array $orderBy = null): ?Donation;

    /**
     * Normally called just as part of releaseMatchFunds which also releases the funds in Redis. But
     * used separately in case of a crash when we would need to release the funds in Redis whether or not
     * we have any FundingWithdrawals in MySQL.
     */
    public function removeAllFundingWithdrawalsForDonation(Donation $donation): void;

    /**
     * Re-queues proxy objects to Salesforce en masse.
     *
     * By using FIFO queues and deduplicating on UUID if there are multiple consumers, we should make it unlikely
     * that Salesforce hits Donation record lock contention issues.
     *
     * @return int  Number of objects pushed
     *
     * @psalm-suppress PossiblyUnusedReturnValue Psalm bug? Value is used in
     * \MatchBot\Application\Commands\PushDonations::doExecute
     *
     */
    public function pushSalesforcePending(\DateTimeImmutable $now, MessageBusInterface $bus): int;

    /**
     * Finds all successful donations from the donor with the given stripe customer ID.
     *
     * In principle, we would probably prefer to the user ID's we've assigned to donors here
     * instead of the Stripe Customer ID, so we're less tied into stripe, but we don't have those currently in
     * the Donation table. Considering adding that column and writing a script to fill in on all old donations.
     * @return list<Donation>
     */
    public function findAllCompleteForCustomer(StripeCustomerId $stripeCustomerId): array;

    /**
     * @return list<Donation>
     */
    public function findDonationsToSetPaymentIntent(\DateTimeImmutable $atDateTime, int $maxBatchSize): array;

    /**
     * @return list<Donation>
     */
    public function findPreAuthorizedDonationsReadyToConfirm(\DateTimeImmutable $atDateTime, int $limit): array;

    public function maxSequenceNumberForMandate(int $mandateId): ?DonationSequenceNumber;

    /**
     * Returns a limited size list of donation fund tip donations that have been left unpaid for some time and
     * we will want to auto-cancel
     * @return list<UuidInterface>
     */
    public function findStaleDonationFundsTips(\DateTimeImmutable $atDateTime, \DateInterval $cancelationDelay): array;

    /**
     * @return list<UuidInterface>
     */
    public function findPendingByDonorCampaignAndMethod(string $donorStripeId, Salesforce18Id $campaignId, PaymentMethodType $paymentMethodType,): array;

    public function findAndLockOneByUUID(UuidInterface $donationId): ?Donation;

    public function upsert(AbstractStateChanged $changeMessage): void;

    /**
     * @return ?Donation
     * @param ?int $id
     */
    public function find($id);

    /**
     * @return list<Donation>
     */
    public function findBy(array $criteria);

    /**
     * @return ?Donation
     */
    public function findOneBy(array $criteria);
}
