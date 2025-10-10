<?php

 namespace MatchBot\Domain;

use DateTime;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use MatchBot\Application\Commands\ExpirePendingMandates;
use MatchBot\Application\Matching;
use MatchBot\Application\Messenger\DonationUpserted;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Includes a subset of methods from \Doctrine\Persistence\ObjectRepository<Donation>. Not adding all methods
 * to allow for easier in-memory implementation.
 */
interface DonationRepository
{
    /**
     * Finds incomplete donations created long enough ago to be eligible to have their match-funding removed.
     *
     * Excludes any donations created in relation to a regular giving mandate as those have a slightly different way
     * of being matched and unmatched - {@see ExpirePendingMandates::doExecute()}
     *
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

    /**
     * Locks row in DB to prevent concurrent updates. See jira MAT-260
     * Requires an open transaction to be managed by the caller.
     * @throws LockWaitTimeoutException
     * @param array<string, string|null> $criteria
     * @param array<string, string>|null $orderBy
     */
    public function findAndLockOneBy(array $criteria, ?array $orderBy = null): ?Donation;

    /**
     * Re-queues proxy objects to Salesforce en masse.
     *
     * By using FIFO queues and deduplicating on UUID if there are multiple consumers, we should make it unlikely
     * that Salesforce hits Donation record lock contention issues.
     *
     * @return int  Number of objects pushed
     *
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
     * @return list<Donation>
     */
    public function findPendingAndPreAuthedForMandate(UuidInterface $mandateId): array;

    /**
     * @return list<Donation>
     */
    public function findAllForMandate(UuidInterface $mandateId): array;

    /**
     * Returns a limited size list of donation fund tip donations that have been left unpaid for some time and
     * we will want to auto-cancel
     * @return list<UuidInterface>
     */
    public function findStaleDonationFundsTips(\DateTimeImmutable $atDateTime, \DateInterval $cancelationDelay): array;

    /**
     * @param Salesforce18Id<Campaign> $campaignId
     * @return list<UuidInterface>
     */
    public function findPendingByDonorCampaignAndMethod(string $donorStripeId, Salesforce18Id $campaignId, PaymentMethodType $paymentMethodType,): array;

    public function findAndLockOneByUUID(UuidInterface $donationId): ?Donation;

    public function push(DonationUpserted $changeMessage): void;

    /**
     * @return ?Donation
     * @param ?int $id
     */
    public function find($id);

    /**
     * @param array{uuid: UuidInterface[]} $criteria
     * @return list<Donation>
     */
    public function findBy(array $criteria);

    /**
     * @phpstan-param array<string, mixed> $criteria
     * @return ?Donation
     */
    public function findOneBy(array $criteria);

    public function findOneByUUID(UuidInterface $donationUUID): ?Donation;

    /**
     * @return list<Donation>
     */
    public function findAllByPayoutId(string $payoutId): array;

    /**
     * @param Campaign $campaign
     * @return non-negative-int
     */
    public function countCompleteDonationsToCampaign(Campaign $campaign): int;
}
