<?php

namespace MatchBot\Application\Messenger\Handler;

use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Messenger\StripePayout;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\SalesforceWriteProxy;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stripe\Charge;
use Stripe\Collection;
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

        $paidChargeIds = [];
        $attributes = [
            'limit' => 100,
            'payout' => $payoutId,
            'type' => 'payment',
        ];

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

            return;
        }

        // Auto page, iterating in reverse chronological order. https://stripe.com/docs/api/pagination/auto?lang=php
        foreach ($balanceTransactions->autoPagingIterator() as $balanceTransaction) {
            $paidChargeIds[] = $balanceTransaction->source;
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

        $chargeIds = $this->getOriginalDonationChargeIds($paidChargeIds, $connectAccountId, $payoutCreated);

        if ($chargeIds === []) {
            $this->logger->error(sprintf(
                'Payout: Exited with no original donation charge IDs for Payout ID %s',
                $payoutId,
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
            'Payout: Updating paid donations complete for stripe payout #%s, persisted %s',
            $payoutId,
            $count,
        ));
    }

    /**
     * @param string[]  $paidChargeIds  Original charge IDs from balance txn lines.
     * @param string    $connectAccountId
     * @param DateTime  $payoutCreated
     * @return string[] Original TBG Charge IDs (`ch_...`).
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
        $tz = new \DateTimeZone('Europe/London');
        $fromDate->sub(new \DateInterval('P60D'))->setTimezone($tz);
        $toDate->add(new \DateInterval('P1D'))->setTimezone($tz);

        // Get charges (`ch_...`) related to the charity's Connect account and then get
        // their corresponding transfers (`tr_...`).
        $moreCharges = true;
        $lastChargeId = null;
        $sourceTransferIds = [];

        $chargeListParams = [
            'created' => [
                'gt' => $fromDate->getTimestamp(),
                'lt' => $toDate->getTimestamp(),
            ],
            'limit' => 100,
        ];

        while ($moreCharges) {
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

        $donations = $this->donationRepository->findWithTransferIdInArray($sourceTransferIds);
        $originalChargeIds = array_map(static fn(Donation $donation) => $donation->getChargeId(), $donations);

        $this->logger->info(
            sprintf('Payout: Finished getting original Charge IDs, found %s', count($originalChargeIds))
        );

        return $originalChargeIds;
    }
}
