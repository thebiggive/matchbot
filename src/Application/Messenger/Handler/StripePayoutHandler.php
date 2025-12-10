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
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Takes `Payout` messages off the queue, calls back out to Stripe to find out which donations
 * to mark Paid, and marks updates for a *future* push to Salesforce. Large payouts are liable to run
 * over the time limit for SQS acks if we push to Salesforce immediately.
 */
#[AsMessageHandler]
class StripePayoutHandler
{
    /**
     * @var int How many levels of previous payout to check. We know that when a payout takes 3 or more tries, the
     *          successful one might not directly reference the payout with the original charges that we need to
     *          reconcile donations' statuses. We limit the levels to 10, more than we've seen to date, to ensure
     *          we can never end up in an infinite loop.
     */
    private const int MAX_RETRY_DEPTH = 10;
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
        $connectAccountId = $payout->connectAccountId;
        $payoutId = $payout->payoutId;

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
                $exception->getStripeCode() ?? 'null-stripe-code',
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
            // Outside of production we expect Stripe to combine things from multiple test environments
            // (staging & regtest) into one, so we may get pings re payouts where we don't recognise any
            // donations.
            $logLevel = (getenv('APP_ENV') === 'production') ? LogLevel::ERROR : LogLevel::INFO;
            $this->logger->log($logLevel, sprintf(
                'Payout: Exited with no original donation charge IDs for Payout ID %s, account %s',
                $payoutId,
                $connectAccountId,
            ));

            return;
        }

        $this->entityManager->beginTransaction();

        foreach ($chargeIds as $chargeId) {
            $donation = $this->donationRepository->findAndLockOneBy(['chargeId' => $chargeId]);

            // If a donation was not found, then it's most likely from a different
            // sandbox and therefore we info log this. Typically this should happen for
            // all donations in the batch but we continue looping so that behaviour for
            // other donations remains consistent if not.
            //
            // In prod if we can't find the donation it's an error.
            if (!$donation) {
                $logLevel = (getenv('APP_ENV') === 'production') ? 'ERROR' : 'INFO';

                $this->logger->log(
                    $logLevel,
                    sprintf('Payout: Donation not found with Charge ID %s', $chargeId ?? 'null')
                );

                continue;
            }

            if ($donation->getDonationStatus() === DonationStatus::Collected) {
                // Fix for donations affected by the fee fallback bug where original fees were set low.
                // We detect this by checking for suspiciously low fees (£0.10 or less) on collected
                // donations and fetching the correct value from Stripe.
                if (bccomp($donation->getOriginalPspFee(), '0.10', 2) <= 0) {
                    $this->correctDonationFeeFromStripe($donation);
                }

                // We're confident to set donation status to paid because this
                // method is called only when Stripe event `payout.paid` is received.
                $donation->recordPayout($payoutId, $payoutInfo['arrivalDate']);

                $donation->setSalesforcePushStatus(SalesforceWriteProxy::PUSH_STATUS_PENDING_UPDATE);

                $this->logger->info("Marked donation ID {$donation->getId()} paid based on stripe payout #{$payoutId}");

                $count++;
                continue;
            }

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
                        $chargeId ?? 'null',
                    )
                );
            }
        }

        $this->entityManager->flush();
        $this->entityManager->commit();

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
     * @return array{created: \DateTimeImmutable, arrivalDate: \DateTimeImmutable, chargeIds: array<string>, ...}
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
                ...$payoutInfo,
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
            'arrivalDate' => $payoutInfo['arrivalDate'],
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
     * @return array{
     *
     *     created: \DateTimeImmutable,
     *     chargeIds: array<string>
     *         }
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
     * @return array{
     *     arrivalDate: \DateTimeImmutable,
     *     created: \DateTimeImmutable,
     *     status: string
     * }
     */
    private function getPayoutInfo(string $payoutId, string $connectAccountId): array
    {
        $stripePayout = $this->stripeClient->payouts->retrieve(
            $payoutId,
            null,
            ['stripe_account' => $connectAccountId],
        );

        $payoutArrivalDate = \DateTimeImmutable::createFromFormat('U', (string) $stripePayout->arrival_date);
        $payoutCreated = \DateTimeImmutable::createFromFormat('U', (string) $stripePayout->created);
        if (! $payoutCreated || !$payoutArrivalDate) {
            throw new \Exception('Bad date format from stripe');
        }

        return [
            'arrivalDate' => $payoutArrivalDate,
            'created' => $payoutCreated,
            'status' => $stripePayout->status,
        ];
    }

    /**
     * Corrects the original PSP fee for a donation by fetching the actual fee from Stripe's balance transaction.
     *
     * @todo Delete after CC25 payouts are complete.
     */
    private function correctDonationFeeFromStripe(Donation $donation): void
    {
        $chargeId = $donation->getChargeId();
        if ($chargeId === null) {
            $this->logger->warning(sprintf(
                'Payout: Cannot correct fee for donation %s - no charge ID',
                $donation->getUuid()
            ));
            return;
        }

        try {
            $charge = $this->stripeClient->charges->retrieve($chargeId);

            $balanceTransactionId = $charge->balance_transaction;
            if (!\is_string($balanceTransactionId)) {
                $this->logger->warning(sprintf(
                    'Payout: Cannot correct fee for donation %s - balance transaction not available on charge %s',
                    $donation->getUuid(),
                    $chargeId
                ));
                return;
            }

            $balanceTransaction = $this->stripeClient->balanceTransactions->retrieve($balanceTransactionId);

            $originalFeeFractional = (string) $balanceTransaction->fee;
            $startingFee = $donation->getOriginalPspFee();
            $donation->setOriginalPspFeeFractional($originalFeeFractional);

            $this->logger->info(sprintf(
                'Payout: Corrected fee for donation %s from %s to %s (from balance transaction %s)',
                $donation->getUuid(),
                $startingFee,
                $donation->getOriginalPspFee(),
                $balanceTransactionId
            ));
        } catch (ApiErrorException $exception) {
            $this->logger->error(sprintf(
                'Payout: Failed to correct fee for donation %s: %s',
                $donation->getUuid(),
                $exception->getMessage()
            ));
        }
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
     * @return array<string|null> Original platform charge IDs (`ch_...`).
     */
    private function getOriginalDonationChargeIds(
        array $paidChargeIds,
        string $connectAccountId,
        \DateTimeImmutable $payoutCreated
    ): array {
        $this->logger->info("Payout: Getting original TBG charge IDs related to payout's Charge IDs");

        // Payouts' usual scheduled as of 2024 is a 2 week minimum offset (give or take a calendar day)
        // with a fixed day of the week for payouts, making the maximum normal lag 21 days (or a bit less
        // sometimes when donation funds were used).
        // However we not uncommonly see delays up to a few months after a big campaign, before a small minority of
        // charities complete Stripe-required info and make themselves eligible to receive a payout. For now
        // we leave 6 months before we start firing alarms for devs in situations like that.
        $tz = new \DateTimeZone('Europe/London');
        $fromDate = $payoutCreated->sub(new \DateInterval('P6M'))->setTimezone($tz);
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
                /** @var string $source_transfer */
                $source_transfer = $charge->source_transfer;
                /** @psalm-suppress DocblockTypeContradiction */
                if (!is_string($source_transfer)) {
                    $this->logger->error("source transfer not of expected type");
                }
                $sourceTransferIds[] = $source_transfer;
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
