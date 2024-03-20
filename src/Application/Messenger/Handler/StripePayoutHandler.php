<?php

namespace MatchBot\Application\Messenger\Handler;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Messenger\StripePayout;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\SalesforceWriteProxy;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stripe\BalanceTransaction;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Component\Messenger\Handler\MessageHandlerInterface;

/**
 * Takes `Payout` messages off the queue, calls back out to Stripe to find out which donations
 * to mark Paid, and marks updates for a *future* push to Salesforce. Large payouts are liable to run
 * over the time limit for SQS acks if we push to Salesforce immediately.
 */
class StripePayoutHandler implements MessageHandlerInterface
{
    /**
     * @var int How many levels of previous payout to check. We know that when a payout takes 3 or more tries, the
     *          successful one might not directly reference the payout with the original charges that we need to
     *          reconcile donations' statuses. We limit the levels to 10, more than we've seen to date, to ensure
     *          we can never end up in an infinite loop.
     */
    private const MAX_RETRY_DEPTH = 10;
    /** @var string[] */
    private array $processedPayoutIds = [];

    public function __construct(
        private DonationRepository $donationRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private StripeClient $stripeClient,
    ) {
    }

    public function __invoke(StripePayout $payout): void
    {
        $count = 0;
        $connectAccountId = $payout->getConnectAccountId();
        $payoutId = $payout->getPayoutId();

        try {
            $payoutInfo = $this->processSuccessfulPayout(
                payoutId: $payoutId,
                connectAccountId: $connectAccountId,
            );
        } catch (ApiErrorException $exception) {
            $this->logger->error(sprintf(
                'Stripe Balance Transaction lookup error for Payout ID %s, %s [%s]: %s',
                $payoutId,
                get_class($exception),
                $exception->getStripeCode(),
                $exception->getMessage(),
            ));

            return;
        }

        if (count($payoutInfo['chargeIds']) === 0) {
            $this->logger->info(sprintf(
                'Payout: Exited with no paid Charge IDs for Payout ID %s, account %s',
                $payoutId,
                $connectAccountId,
            ));

            return;
        }

        $chargeIds = $this->getOriginalDonationChargeIds(
            $payoutInfo['chargeIds'],
            $connectAccountId,
            $payoutInfo['created'],
        );

        if ($chargeIds === []) {
            $this->logger->error(sprintf(
                'Payout: Exited with no original donation charge IDs for Payout ID %s, account %s',
                $payoutId,
                $connectAccountId,
            ));
            // Temporary log lots of detail to help diagnose payout reconciliation edge cases.
            $this->logger->info(sprintf(
                'Used created datetime %s and charge IDs list: %s',
                $payoutInfo['created']->format('r'),
                implode(',', $payoutInfo['chargeIds']),
            ));

            return;
        }

        foreach ($chargeIds as $chargeId) {
            $this->entityManager->beginTransaction();

            $donation = $this->donationRepository->findAndLockOneBy(['chargeId' => $chargeId]);

            // If a donation was not found, then it's most likely from a different
            // sandbox and therefore we info log this. Typically this should happen for
            // all donations in the batch but we continue looping so that behaviour for
            // other donations remains consistent if not.
            //
            // In prod if we can't find the donation it's an error.
            if (!$donation) {
                $logLevel = (getenv('APP_ENV') === 'production') ? 'ERROR' : 'INFO';

                $this->logger->log($logLevel, sprintf('Payout: Donation not found with Charge ID %s', $chargeId));
                $this->entityManager->commit();
                continue;
            }

            if ($donation->getDonationStatus() === DonationStatus::Collected) {
                // We're confident to set donation status to paid because this
                // method is called only when Stripe event `payout.paid` is received.
                $donation->setDonationStatus(DonationStatus::Paid);

                $donation->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE);
                $this->entityManager->persist($donation);
                $this->entityManager->flush();
                $this->entityManager->commit();

                $this->logger->info("Marked donation #{$donation->getId()} paid based on stripe payout #{$payoutId}");

                $count++;
                continue;
            }

            // Else commit the txn without persisting anything, ready for a new one.
            $this->entityManager->commit();

            if ($donation->getDonationStatus() !== DonationStatus::Paid) {
                // Skip updating donations in non-Paid statuses but continue to check the remainder.
                // 'Refunded' is an expected status when looking through the balance txn list for a
                // Connect account's payout, e.g.:
                // Payment refund (£112.50)  £0.00  (£112.50) Refund for py_1IUDF94FoHYWqtVFeuW0E4Yb ...
                // Payment         £112.50 (£14.54)   £97.96  py_1IUDF94FoHYWqtVFeuW0E4Yb ...
                // So we log that case with INFO level (no alert / action generally) and others with ERROR.
                $this->logger->log(
                    $donation->getDonationStatus() === DonationStatus::Refunded ? LogLevel::INFO : LogLevel::ERROR,
                    sprintf(
                        'Payout: Skipping donation status %s found for Charge ID %s',
                        $donation->getDonationStatus()->value,
                        $chargeId,
                    )
                );
            }
        }

        $this->logger->info(sprintf(
            'Payout: Updating paid donations complete for stripe payout #%s, persisted %d',
            $payoutId,
            $count,
        ));
    }

    /**
     * Logs a warning and returns no charges if payout is in fact not successful. This is expected short-term while
     * we run temporary scripts, and should not happen later when only webhooks lead to `StripePayoutHandler` messages.
     *
     * @return array{created: \DateTimeImmutable, chargeIds: array<string>}
     * @throws ApiErrorException if balance transaction listing fails.
     */
    private function processSuccessfulPayout(string $payoutId, string $connectAccountId): array
    {
        $payoutInfo = $this->getPayoutInfo($payoutId, $connectAccountId);

        if ($payoutInfo['status'] !== 'paid') {
            $this->logger->warning(sprintf(
                'Payout: Skipping payout ID %s for Connect account ID %s; status is %s',
                $payoutId,
                $connectAccountId,
                $payoutInfo['status'],
            ));

            return [
                'created' => $payoutInfo['created'],
                'chargeIds' => [],
            ];
        }

        $this->logger->info(sprintf(
            'Payout: Getting all charges related to Payout ID %s for Connect account ID %s',
            $payoutId,
            $connectAccountId,
        ));

        $ids = $this->getChargeAndAdditionalPayoutIds($payoutId, $connectAccountId);
        $paidChargeIds = $ids['chargeIds'];

        if (count($ids['payoutIds']) > 0) {
            $this->logger->info(sprintf(
                'Payout: Found %d extra %s Payout IDs to map donations from: %s',
                count($ids['payoutIds']),
                $connectAccountId,
                implode(', ', $ids['payoutIds']),
            ));

            foreach ($ids['payoutIds'] as $extraPayoutId) {
                $extraPayoutInfo = $this->processChargesFromPreviousPayout(
                    payoutId: $extraPayoutId,
                    connectAccountId: $connectAccountId,
                    retryDepth: 0,
                );
                // Include all previously delayed payouts' charge IDs in the handler's main list.
                $paidChargeIds = [...$paidChargeIds, ...$extraPayoutInfo['chargeIds']];
            }
        }

        $this->logger->info(
            sprintf(
                'Payout: Getting all Connect account paid Charge IDs for Payout ID %s complete, found %s',
                $payoutId,
                count($paidChargeIds),
            )
        );

        return [
            'created' => $payoutInfo['created'],
            'chargeIds' => $paidChargeIds,
        ];
    }

    /**
     * Called from `processSuccessfulPayout()` when there is a reference to an earlier payout that contains
     * charges reflected in the successful one's total, or from this method if a failed payout also references
     * previous ones that make up part of its total value. The `$payoutId` to this method is the earlier one so we
     * do not check its status, which is likely "failed".
     *
     * @return array{created: \DateTimeImmutable, chargeIds: array<string>}
     * @throws ApiErrorException if balance transaction listing fails.
     */
    private function processChargesFromPreviousPayout(
        string $payoutId,
        string $connectAccountId,
        int $retryDepth
    ): array {
        $payoutInfo = $this->getPayoutInfo($payoutId, $connectAccountId);

        $this->logger->info(sprintf(
            'Payout: Getting all charges related to *earlier* Payout ID %s for Connect account ID %s',
            $payoutId,
            $connectAccountId,
        ));

        $ids = $this->getChargeAndAdditionalPayoutIds($payoutId, $connectAccountId);
        if ($ids['payoutIds'] !== []) {
            if ($retryDepth >= self::MAX_RETRY_DEPTH) {
                $this->logger->error(sprintf(
                    'Payout: Max retry depth %d reached for Connect account ID %s, Payout ID %s',
                    self::MAX_RETRY_DEPTH,
                    $connectAccountId,
                    $payoutId,
                ));

                return [
                    'created' => $payoutInfo['created'],
                    'chargeIds' => [],
                ];
            }

            $this->logger->info(sprintf(
                'Payout: Found %d extra %s Payout IDs to map donations from: %s',
                count($ids['payoutIds']),
                $connectAccountId,
                implode(', ', $ids['payoutIds']),
            ));

            foreach ($ids['payoutIds'] as $extraPayoutId) {
                if (!in_array($extraPayoutId, $this->processedPayoutIds, true)) {
                    $extraPayoutInfo = $this->processChargesFromPreviousPayout(
                        payoutId: $extraPayoutId,
                        connectAccountId: $connectAccountId,
                        retryDepth: $retryDepth + 1,
                    );
                    // Include all previously delayed payouts' charge IDs in the handler's main list.
                    $ids['chargeIds'] = [...$ids['chargeIds'], ...$extraPayoutInfo['chargeIds']];
                    $this->processedPayoutIds[] = $extraPayoutId;
                }
            }
        }

        $this->logger->info(
            sprintf(
                'Payout: Getting all Connect account paid Charge IDs for *earlier* Payout ID %s complete, found %s',
                $payoutId,
                count($ids['chargeIds']),
            )
        );

        return [
            'created' => $payoutInfo['created'],
            'chargeIds' => $ids['chargeIds'],
        ];
    }

    /**
     * @return array{created: \DateTimeImmutable, status: string}
     */
    private function getPayoutInfo(string $payoutId, string $connectAccountId): array
    {
        $stripePayout = $this->stripeClient->payouts->retrieve(
            $payoutId,
            null,
            ['stripe_account' => $connectAccountId],
        );

        $payoutCreated = \DateTimeImmutable::createFromFormat('U', (string) $stripePayout->created);
        if (! $payoutCreated) {
            throw new \Exception('Bad date format from stripe');
        }

        return [
            'created' => $payoutCreated,
            'status' => $stripePayout->status,
        ];
    }

    /**
     * @return array{chargeIds: array<string>, payoutIds: array<string>}
     */
    private function getChargeAndAdditionalPayoutIds(string $payoutId, string $connectAccountId): array
    {
        /** @var string[] $paidChargeIds */
        $paidChargeIds = [];
        $attributes = [
            'limit' => 100,
            'payout' => $payoutId,
        ];

        // Get all balance transactions (`py_...`) related to the specific payout defined in
        // `$attributes`, scoping the lookup to the correct Connect account.
        $balanceTransactions = $this->stripeClient->balanceTransactions->all(
            $attributes,
            ['stripe_account' => $connectAccountId],
        );

        /** @var string[] $extraPayoutIdsToMap */
        $extraPayoutIdsToMap = [];

        // Auto page, iterating in reverse chronological order. https://stripe.com/docs/api/pagination/auto?lang=php
        foreach ($balanceTransactions->autoPagingIterator() as $balanceTransaction) {
            switch ($balanceTransaction->type) {
                case BalanceTransaction::TYPE_PAYMENT:
                    // source is the `py_...` charge ID from the connected account txns.
                    $paidChargeIds[] = (string) $balanceTransaction->source;
                    break;
                case BalanceTransaction::TYPE_PAYOUT_FAILURE: // fallthru, these 2 are handled the same
                case BalanceTransaction::TYPE_PAYOUT_CANCEL:
                    // source is the previous failed payout `po_...` ID.
                    $extraPayoutIdsToMap[] = (string) $balanceTransaction->source;
                    break;
                // Other types are ignored. 'payout' is expected but we don't use it here.
            }
        }

        return ['chargeIds' => $paidChargeIds, 'payoutIds' => $extraPayoutIdsToMap];
    }

    /**
     * @param string[]  $paidChargeIds  Connect acct charge IDs (`py_...`) from `source` property of
     *                                  balance txn `"type": "payment"` lines.
     * @return string[] Original platform charge IDs (`ch_...`).
     */
    private function getOriginalDonationChargeIds(
        array $paidChargeIds,
        string $connectAccountId,
        \DateTimeImmutable $payoutCreated
    ): array {
        $this->logger->info("Payout: Getting original TBG charge IDs related to payout's Charge IDs");

        // Payouts' usual scheduled as of 2022 is a 2 week minimum offset (give or take a calendar day)
        // with a fixed day of the week for payouts, making the maximum normal lag 21 days. However we
        // have had edge cases with bank details problems taking a couple of weeks to resolve, so we now
        // look back up to 2 years in order to still catch charges for status updates if this happens.
        // Once historic donations are reconciled in March 2024, we'll reduce this to 60 days again.

        $tz = new \DateTimeZone('Europe/London');
        $fromDate = $payoutCreated->sub(new \DateInterval('P3Y'))->setTimezone($tz);
        $toDate = $payoutCreated->add(new \DateInterval('P1D'))->setTimezone($tz);

        // Get all charges (`py_...`) related to the charity's Connect account, then list
        // their corresponding transfers (`tr_...`) iff the ID is in `$paidChargeIds`.
        $sourceTransferIds = [];

        $chargeListParams = [
            'created' => [
                'gt' => $fromDate->getTimestamp(),
                'lt' => $toDate->getTimestamp(),
            ],
            'limit' => 100,
        ];

        $charges = $this->stripeClient->charges->all(
            $chargeListParams,
            ['stripe_account' => $connectAccountId],
        );

        foreach ($charges->autoPagingIterator() as $charge) {
            if (in_array($charge->id, $paidChargeIds, true)) {
                $sourceTransferIds[] = $charge->source_transfer;
            }
        }

        $donations = $this->donationRepository->findWithTransferIdInArray($sourceTransferIds);
        $originalChargeIds = array_map(static fn(Donation $donation) => $donation->getChargeId(), $donations);

        $this->logger->info(
            sprintf(
                'Payout: Finished getting original Charge IDs, found %d ' .
                    '(from %d source transfer IDs and %d donations whose transfer IDs matched)',
                count($originalChargeIds),
                count($sourceTransferIds),
                count($donations),
            )
        );

        return $originalChargeIds;
    }
}
