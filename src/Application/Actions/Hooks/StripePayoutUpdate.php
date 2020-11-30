<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Hooks;

use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Domain\Donation;
use Psr\Http\Message\ResponseInterface as Response;
use Stripe\Event;

/**
 * Handle payout.paid and payout.failed events from a Stripe Connect app webhook.
 *
 * @return Response
 */
class StripePayoutUpdate extends Stripe
{
    /**
     * @return Response
     */
    protected function action(): Response
    {
        $validationErrorResponse = $this->prepareEvent(
            $this->request,
            $this->stripeSettings['connectAppWebhookSecret']
        );

        if ($validationErrorResponse !== null) {
            return $validationErrorResponse;
        }

        $this->logger->info(sprintf('Received Stripe Connect app event type "%s"', $this->event->type));

        switch ($this->event->type) {
            case 'payout.paid':
                return $this->handlePayoutPaid($this->event);
            case 'payout.failed':
                $this->logger->error(sprintf('payout.failed for ID %s', $this->event->data->object->id));
                return $this->respond(new ActionPayload(200));
            default:
                $this->logger->warning(sprintf('Unsupported event type "%s"', $this->event->type));
                return $this->respond(new ActionPayload(204));
        }
    }

    private function handlePayoutPaid(Event $event): Response
    {
        $count = 0;
        $payoutId = $event->data->object->id;
        $connectAccountId = $event->account;

        $this->logger->info(sprintf(
            'Payout: Getting all charges related to Payout ID %s for Connect account ID %s',
            $payoutId,
            $connectAccountId,
        ));

        if (!$event->data->object->automatic) {
            // If we try to use the `payout` filter attribute in the `balanceTransactions` call
            // below in the manual payout case, Stripe errors out with "Balance transaction history
            // can only be filtered on automatic transfers, not manual".
            $this->logger->warning(sprintf('Skipping processing of manual Payout ID %s', $payoutId));
            return $this->respond(new ActionPayload(204));
        }

        $hasMore = true;
        $lastBalanceTransactionId = null;
        $paidChargeIds = [];
        $attributes = [
            'limit' => 100,
            'payout' => $payoutId,
            'type' => 'payment',
        ];

        while ($hasMore) {
            // Get all balance transactions (`py_...`) related to the charity's Connect account
            $balanceTransactions = $this->stripeClient->balanceTransactions->all(
                $attributes,
                ['stripe_account' => $connectAccountId],
            );

            foreach ($balanceTransactions->data as $balanceTransaction) {
                $paidChargeIds[] = $balanceTransaction->source;
                $lastBalanceTransactionId = $balanceTransaction->id;
            }

            $hasMore = $balanceTransactions->has_more;

            // We get a Stripe exception if we start this with a null or empty value,
            // so we only include this once we've iterated the first time and captured
            // a transaction Id.
            if ($lastBalanceTransactionId !== null) {
                $attributes['start_after'] = $lastBalanceTransactionId;
            }
        }
        $this->logger->info(
            sprintf('Payout: Getting all Connect account paid Charge IDs complete, found: %s', count($paidChargeIds))
        );

        if (count($paidChargeIds) > 0) {
            foreach ($this->getTransferIds($paidChargeIds, $connectAccountId) as $transferId) {
                $chargeId = $this->getChargeId($transferId);

                /** @var Donation $donation */
                $donation = $this->donationRepository->findOneBy(['chargeId' => $chargeId]);

                // If a donation was not found, then it's most likely from a different
                // sandbox and therefore we info log this and respond with 204.
                if (!$donation) {
                    $this->logger->info(sprintf('Payout: Donation not found with Charge ID %s', $chargeId));
                    return $this->respond(new ActionPayload(204));
                }

                if ($donation->getDonationStatus() === 'Collected') {
                    // We're confident to set donation status to paid because this
                    // method is called only when Stripe event `payout.paid` is received.
                    $donation->setDonationStatus('Paid');

                    $this->entityManager->persist($donation);
                    $this->donationRepository->push($donation, false);

                    $count++;
                } elseif ($donation->getDonationStatus() !== 'Paid') {
                    $this->logger->error(
                        sprintf('Payout: Unexpected donation status found for Charge ID %s', $chargeId)
                    );
                    return $this->respond(new ActionPayload(400));
                }
            }
        }

        $this->logger->info(sprintf('Payout: Acknowledging paid donations complete, persisted: %s', $count));
        return $this->respondWithData($event->data->object);
    }

    /**
     * @param array $paidChargeIds  Transaction line (`py_...`) which is also a charge ID in this case.
     * @param string $connectAccountId
     * @return string[] Transfer IDs (`tr_...`)
     */
    private function getTransferIds(array $paidChargeIds, string $connectAccountId): array
    {
        $this->logger->info(
            sprintf('Payout: Getting all related Transfer IDs for Connect Account: %s', $connectAccountId)
        );
        $transferIds = [];

        foreach ($paidChargeIds as $chargeId) {
            // Get charges (`ch_...`) related to the charity's Connect account and then get
            // their corresponding transfers (`tr_...`).
            $charge = $this->stripeClient->charges->retrieve(
                $chargeId,
                null,
                ['stripe_account' => $connectAccountId],
            );
            $transferIds[] = $charge->source_transfer;
        }

        $this->logger->info(
            sprintf('Payout: Finished getting all related Transfer IDs, found: %s', count($transferIds))
        );
        return $transferIds;
    }

    private function getChargeId($transferId): string
    {
        $this->logger->info(sprintf('Payout: Getting Charge Id from Transfer ID: %s', $transferId));

        // Not specifying `stripe-account` param will default the search to TBG's main account
        $transfer = $this->stripeClient->transfers->retrieve(
            $transferId
        );

        return $transfer->source_transaction;
    }
}
