<?php

namespace MatchBot\Application\Messenger\Handler;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Messenger\StripePayout;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Redis;
use Stripe\Charge;
use Stripe\Collection;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\Transfer;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

/**
 * Takes `Payout` messages off the queue, calls back out to Stripe to find out which donations
 * to mark Paid, and pushes updates to Salesforce.
 */
class StripePayoutHandler implements MessageHandlerInterface
{
    public function __construct(
        private DonationRepository $donationRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private Redis $redis,
        private StripeClient $stripeClient,
    ) {
    }

    public function __invoke(StripePayout $payout): void
    {
        $count = 0;
        $connectAccountId = $payout->getConnectAccountId();
        $payoutId = $payout->getPayoutId();

        /** @var \Stripe\Payout $stripePayout */
        $stripePayout = $this->stripeClient->payouts->retrieve(
            $payoutId,
            null,
            ['stripe_account' => $connectAccountId],
        );
        $payoutCreated = (new DateTime())->setTimestamp($stripePayout->created);

        $this->logger->info(sprintf(
            'Payout: Getting all charges related to Payout ID %s for Connect account ID %s',
            $payoutId,
            $connectAccountId,
        ));

        $hasMore = true;
        $lastBalanceTransactionId = null;
        $paidChargeIds = [];
        $attributes = [
            'limit' => 100,
            'payout' => $payoutId,
            'type' => 'payment',
        ];

        while ($hasMore) {
            // Get all balance transactions (`py_...`) related to the specific payout defined in
            // `$attributes`, scoping the lookup to the correct Connect account.
            try {
                $balanceTransactions = $this->stripeClient->balanceTransactions->all(
                    $attributes,
                    ['stripe_account' => $connectAccountId],
                );
            } catch (ApiErrorException $exception) {
                $this->logger->error(sprintf(
                    'Stripe Balance Transaction lookup error for Payout ID %s, %s [%s]: %s',
                    $payoutId,
                    get_class($exception),
                    $exception->getStripeCode(),
                    $exception->getMessage(),
                ));
                break;
            }

            foreach ($balanceTransactions->data as $balanceTransaction) {
                $paidChargeIds[] = $balanceTransaction->source;
                $lastBalanceTransactionId = $balanceTransaction->id;
            }

            $hasMore = $balanceTransactions->has_more;

            // We get a Stripe exception if we start this with a null or empty value,
            // so we only include this once we've iterated the first time and captured
            // a transaction Id.
            if ($hasMore && $lastBalanceTransactionId !== null) {
                $attributes['starting_after'] = $lastBalanceTransactionId;
                $this->logger->debug(sprintf(
                    'Stripe Balance Transaction for Payout ID %s will next use starting_after: %s',
                    $payoutId,
                    $lastBalanceTransactionId,
                ));
            }
        }
        $this->logger->info(
            sprintf(
                'Payout: Getting all Connect account paid Charge IDs for Payout ID %s complete, found %s',
                $payoutId,
                count($paidChargeIds),
            )
        );

        if (count($paidChargeIds) === 0) {
            $this->logger->info(sprintf(
                'Payout: Exited with no paid Charge IDs for Payout ID %s',
                $payoutId,
            ));

            return;
        }

        foreach ($this->getOriginalDonationChargeIds($paidChargeIds, $connectAccountId, $payoutCreated) as $chargeId) {
            /** @var Donation $donation */
            $donation = $this->donationRepository->findOneBy(['chargeId' => $chargeId]);

            // If a donation was not found, then it's most likely from a different
            // sandbox and therefore we info log this. Typically this should happen for
            // all donations in the batch but we continue looping so that behaviour for
            // other donations remains consistent if not.
            if (!$donation) {
                $this->logger->info(sprintf('Payout: Donation not found with Charge ID %s', $chargeId));
                continue;
            }

            if ($donation->getDonationStatus() === 'Collected') {
                // We're confident to set donation status to paid because this
                // method is called only when Stripe event `payout.paid` is received.
                $donation->setDonationStatus('Paid');

                $this->entityManager->persist($donation);
                $this->donationRepository->push($donation, false);

                $count++;
            } elseif ($donation->getDonationStatus() !== 'Paid') {
                // Skip updating donations in non-Paid statuses but continue to check the remainder.
                // 'Refunded' is an expected status when looking through the balance txn list for a
                // Connect account's payout, e.g.:
                // Payment refund (£112.50)  £0.00  (£112.50) Refund for py_1IUDF94FoHYWqtVFeuW0E4Yb ...
                // Payment         £112.50 (£14.54)   £97.96  py_1IUDF94FoHYWqtVFeuW0E4Yb ...
                // So we log that case with INFO level (no alert / action generally) and others with ERROR.
                $this->logger->log(
                    $donation->getDonationStatus() === 'Refunded' ? LogLevel::INFO : LogLevel::ERROR,
                    sprintf(
                        'Payout: Skipping donation status %s found for Charge ID %s',
                        $donation->getDonationStatus(),
                        $chargeId,
                    )
                );
            }
        }

        if ($count > 0) {
            $this->entityManager->flush();
        }

        $this->logger->info(sprintf('Payout: Updating paid donations complete, persisted %s', $count));
    }

    /**
     * @param string[]  $paidChargeIds  Original charge IDs from balance txn lines.
     * @param string    $connectAccountId
     * @param DateTime  $payoutCreated
     * @return string[] Origianl TBG Charge IDs (`ch_...`).
     */
    private function getOriginalDonationChargeIds(
        array $paidChargeIds,
        string $connectAccountId,
        DateTime $payoutCreated
    ): array {
        $this->logger->info("Payout: Getting original TBG charge IDs related to payout's Charge IDs");

        $fromDate = clone $payoutCreated;
        $toDate = clone $payoutCreated;

        // Payouts' usual scheduled as of 2022 is a 2 week minimum offset (give or take a calendar day)
        // with a fixed day of the week for payouts, making the maximum normal lag 21 days. However we
        // have had edge cases with bank details problems taking a couple of weeks to resolve, so we now
        // look back up to 60 days in order to still catch charges for status updates if this happens.
        // We 'fuzz' the dates by using 00:00 just before/after the window so that we can use the
        // timestamp as a Redis cache key, and expect each charity's payout to be able to use the same
        // saved Transfer to Charge ID map, regardless of whether their payouts happened a few seconds
        // or minutes apart.
        $tz = new \DateTimeZone('Europe/London');
        $fromDate->sub(new \DateInterval('P60D'))->setTimezone($tz)->setTime(0, 0);
        $toDate->add(new \DateInterval('P1D'))->setTimezone($tz)->setTime(0, 0);

        $createdConstraint = [
            'gt' => $fromDate->getTimestamp(),
            'lt' => $toDate->getTimestamp(),
        ];

        // Get charges (`ch_...`) related to the charity's Connect account and then get
        // their corresponding transfers (`tr_...`).
        $moreCharges = true;
        $lastChargeId = null;
        $sourceTransferIds = [];

        $chargeListParams = [
            'created' => $createdConstraint,
            'limit' => 100,
        ];

        while ($moreCharges) {
            /** @var Charge[]|Collection $charges */
            $charges = $this->stripeClient->charges->all(
                $chargeListParams,
                ['stripe_account' => $connectAccountId],
            );

            foreach ($charges->data as $charge) {
                $lastChargeId = $charge->id;

                if (!in_array($charge->id, $paidChargeIds, true)) {
                    continue;
                }

                $sourceTransferIds[] = $charge->source_transfer;
            }

            $moreCharges = $charges->has_more;

            // We get a Stripe exception if we start this with a null or empty value,
            // so we only include this once we've iterated the first time and captured
            // a transaction Id.
            if ($moreCharges && $lastChargeId !== null) {
                $chargeListParams['starting_after'] = $lastChargeId;
            }
        }

        if ($this->redis->exists($this->buildRedisTransferToChargeHashKey($fromDate->getTimestamp())) === 0) {
            $this->buildTranferIdToSourceChargeMap($fromDate->getTimestamp(), $toDate->getTimestamp());
        }

        $originalChargeIds = [];
        foreach ($sourceTransferIds as $sourceTransferId) {
            $originalChargeIds[] = $this->getOriginalChargeId($sourceTransferId, $fromDate->getTimestamp());
        }

        $this->logger->info(
            sprintf('Payout: Finished getting original Charge IDs, found %s', count($originalChargeIds))
        );

        return $originalChargeIds;
    }

    private function getOriginalChargeId(string $transferId, int $startTimestamp): ?string
    {
        $chargeId = $this->redis->hGet(
            $this->buildRedisTransferToChargeHashKey($startTimestamp),
            $transferId,
        );

        if ($chargeId === false) {
            $this->logger->error(sprintf(
                "Could not find original charge for Transfer %s in the IDs map from %d",
                $chargeId,
                $startTimestamp,
            ));

            return null;
        }

        return $chargeId;
    }

    private function buildTranferIdToSourceChargeMap(int $fromTime, int $toTime): void
    {
        // We saw this taking around 12 minutes when covering the biggest CC21 week – it has to page
        // through *all* TBG transfers 100 per call! This is primarily why it is cached in Redis, as
        // prior to this being built this whole process had to be done for *each* charity payout, i.e.
        // potentially ~1,000 times from the same day.
        $this->logger->info('Payout: Starting transfer ID to source charge ID map build, may take a while...');

        $redisHashKey = $this->buildRedisTransferToChargeHashKey($fromTime);

        $moreTransfers = true;
        $lastTransferId = null;

        // In a CC21 spot check, the TBG Account transfer created time was the same second as the
        // Connected Account's charge created time. We check 10s either side here just in case there's
        // sometimes a small lag.
        $transfersCreatedConstraint = [
            'gt' => $fromTime,
            'lt' => $toTime,
        ];

        $transferListParams = [
            'created' => $transfersCreatedConstraint,
            'limit' => 100,
        ];

        while ($moreTransfers) {
            // Not specifying `stripe-account` param will default the search to TBG's main account
            /** @var Transfer[]|Collection $transfers */
            $transfers = $this->stripeClient->transfers->all($transferListParams);

            foreach ($transfers->data as $transfer) {
                $lastTransferId = $transfer->id;
                $this->redis->hSet($redisHashKey, $transfer->id, $transfer->source_transaction);
            }

            $moreTransfers = $transfers->has_more;

            // We get a Stripe exception if we start this with a null or empty value,
            // so we only include this once we've iterated the first time and captured
            // a transaction Id.
            if ($moreTransfers && $lastTransferId !== null) {
                $transferListParams['starting_after'] = $lastTransferId;
            }
        }

        $this->redis->expireAt($redisHashKey, time() + 14 * 60 * 60); // Cache results for 14h.

        $this->logger->info('Payout: ...completed transfer ID to source charge ID map build!');
    }

    private function buildRedisTransferToChargeHashKey(int $startTimestamp): string
    {
        return "stripe-payout-transfers-v2-$startTimestamp";
    }
}
