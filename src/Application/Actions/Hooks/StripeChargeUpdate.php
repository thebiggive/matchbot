<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Hooks;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Stripe\Charge;
use Stripe\Event;
use Stripe\StripeClient;

/**
 * Handle charge.succeeded and charge.refunded events from a Stripe account webhook.
 *
 * @return Response
 */
class StripeChargeUpdate extends Stripe
{
    public function __construct(
        protected DonationRepository $donationRepository,
        protected EntityManagerInterface $entityManager,
        protected StripeClient $stripeClient,
        ContainerInterface $container,
        LoggerInterface $logger,
    ) {
        parent::__construct($container, $logger);
    }

    /**
     * @return Response
     */
    protected function action(): Response
    {
        $validationErrorResponse = $this->prepareEvent(
            $this->request,
            $this->stripeSettings['accountWebhookSecret'],
            false,
        );

        if ($validationErrorResponse !== null) {
            return $validationErrorResponse;
        }

        $this->logger->info(sprintf('Received Stripe account event type "%s"', $this->event->type));

        switch ($this->event->type) {
            case 'charge.refunded':
                return $this->handleChargeRefunded($this->event);
            case 'charge.succeeded':
                return $this->handleChargeSucceeded($this->event);
            default:
                $this->logger->warning(sprintf('Unsupported event type "%s"', $this->event->type));
                return $this->respond(new ActionPayload(204));
        }
    }

    private function handleChargeSucceeded(Event $event): Response
    {
        /** @var Charge $charge */
        $charge = $event->data->object;

        $intentId = $charge->payment_intent;

        /** @var Donation $donation */
        $donation = $this->donationRepository->findOneBy(['transactionId' => $intentId]);

        if (!$donation) {
            $this->logger->info(sprintf('Donation not found with Payment Intent ID %s', $intentId));
            return $this->respond(new ActionPayload(204));
        }

        // For now we support the happy success path,
        // as this is the only event type we're handling right now,
        // convert status to the one SF uses.
        if ($charge->status === 'succeeded') {
            $donation->setChargeId($charge->id);
            $donation->setTransferId($charge->transfer);
            $donation->setDonationStatus('Collected');

            // To give *simulated* webhooks, for Donation API-only load tests, an easy way to complete
            // without crashing, we support skipping the original fee derivation by omitting
            // `balance_transaction`. Real stripe charge.succeeded webhooks should always have
            // an associated Balance Transaction.
            if (!empty($charge->balance_transaction)) {
                $originalFeeFractional = $this->getOriginalFeeFractional(
                    $charge->balance_transaction,
                    $donation->getCurrencyCode(),
                );
                $donation->setOriginalPspFeeFractional($originalFeeFractional);
            }
        } else {
            return $this->validationError(sprintf('Unsupported Status "%s"', $charge->status));
        }

        $this->entityManager->persist($donation);

        // We log if this fails but don't worry the webhook-sending payment client
        // about it. We'll re-try sending the updated status to Salesforce in a future
        // batch sync.
        $this->donationRepository->push($donation, false); // Attempt immediate sync to Salesforce

        return $this->respondWithData($charge);
    }

    private function handleChargeRefunded(Event $event): Response
    {
        $chargeId = $event->data->object->id;
        $amountRefunded = $event->data->object->amount_refunded; // int: pence.

        /** @var Donation $donation */
        $donation = $this->donationRepository->findOneBy(['chargeId' => $chargeId]);

        if (!$donation) {
            $this->logger->info(sprintf('Donation not found with Charge ID %s', $chargeId));
            return $this->respond(new ActionPayload(204));
        }

        $isTipRefund = $donation->getTipAmountFractional() === $amountRefunded;
        $isFullRefund = $donation->getAmountFractionalIncTip() === $amountRefunded;

        // Available status' (pending, succeeded, failed, canceled),
        // see: https://stripe.com/docs/api/refunds/object.
        // For now we support the successful refund path (inc. partial refund IF it's for the tip amount),
        // converting status to the one MatchBot + SF use.
        if ($event->data->object->status !== 'succeeded') {
            return $this->validationError(sprintf('Unsupported Status "%s"', $event->data->object->status));
        }

        if ($isTipRefund) {
            $this->logger->info(sprintf('Setting status to "Refunded Tip" based on charge ID %s. SF subsequently makes tip £0.', $event->data->object->id));
            // See BG2-1946 for 'Refunded Tip' status explanation
            $donation->setDonationStatus('Refunded Tip');
        } elseif ($isFullRefund) {
            $this->logger->info(sprintf(
                'Marking donation %s refunded based on charge ID %s',
                $donation->getUuid(),
                $event->data->object->id,
            ));
            $donation->setDonationStatus('Refunded');
        } else {
            $this->logger->error(sprintf(
                'Skipping unexpected partial non-tip refund amount %s pence for donation %s based on charge ID %s',
                $amountRefunded,
                $donation->getUuid(),
                $event->data->object->id,
            ));
            return $this->respond(new ActionPayload(204));
        }

        $this->entityManager->persist($donation);
        $this->entityManager->flush();

        // Release match funds only if the donation was matched and
        // the refunded amount is equal to the local txn amount.
        // We multiply local donation amount by 100 to match Stripes calculations.
        if ($isFullRefund && $donation->isReversed() && $donation->getCampaign()->isMatched()) {
            $this->donationRepository->releaseMatchFunds($donation);
        }

        $this->donationRepository->push($donation, false); // Attempt immediate sync to Salesforce

        return $this->respondWithData($event->data->object);
    }

    private function getOriginalFeeFractional(string $balanceTransactionId, string $expectedCurrencyCode): int
    {
        $txn = $this->stripeClient->balanceTransactions->retrieve($balanceTransactionId);

        if (count($txn->fee_details) !== 1) {
            $this->logger->warning(sprintf(
                'StripeChargeUpdate::getFee: Unexpected composite fee with %d parts: %s',
                count($txn->fee_details),
                json_encode($txn->fee_details),
            ));
        }

        if ($txn->fee_details[0]->currency !== strtolower($expectedCurrencyCode)) {
            // `fee` should presumably still be in parent account's currency, so don't bail out.
            $this->logger->warning(sprintf(
                'StripeChargeUpdate::getFee: Unexpected fee currency %s',
                $txn->fee_details[0]->currency,
            ));
        }

        if ($txn->fee_details[0]->type !== 'stripe_fee') {
            $this->logger->warning(sprintf(
                'StripeChargeUpdate::getFee: Unexpected type %s',
                $txn->fee_details[0]->type,
            ));
        }

        return $txn->fee;
    }
}
