<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Hooks;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Notifier\StripeChatterInterface;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Stripe\Charge;
use Stripe\Dispute;
use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackHeaderBlock;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackSectionBlock;
use Symfony\Component\Notifier\Bridge\Slack\SlackOptions;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

/**
 * Handle charge.succeeded, charge.refunded and charge.dispute.closed events from a Stripe Direct webhook.
 *
 * @return Response
 */
class StripePaymentsUpdate extends Stripe
{
    private ChatterInterface $chatter;

    public function __construct(
        protected DonationRepository $donationRepository,
        protected EntityManagerInterface $entityManager,
        protected StripeClient $stripeClient,
        ContainerInterface $container,
        LoggerInterface $logger,
    ) {
        /**
         * @var ChatterInterface $chatter
         * Injecting `StripeChatterInterface` directly doesn't work because `Chatter` itself
         * is final and does not implement our custom interface.
         */
        $chatter = $container->get(StripeChatterInterface::class);
        $this->chatter = $chatter;

        parent::__construct($container, $logger);
    }

    /**
     * @return Response
     */
    protected function action(Request $request, Response $response, array $args): Response
    {
        $validationErrorResponse = $this->prepareEvent(
            $request,
            $this->stripeSettings['accountWebhookSecret'],
            false,
            $response,
        );

        if ($validationErrorResponse !== null) {
            return $validationErrorResponse;
        }

        $this->logger->info(sprintf('Received Stripe account event type "%s"', $this->event->type));

        switch ($this->event->type) {
            case Event::CHARGE_DISPUTE_CLOSED:
                return $this->handleChargeDisputeClosed($this->event, $response);
            case Event::CHARGE_REFUNDED:
                return $this->handleChargeRefunded($this->event, $response);
            case Event::CHARGE_SUCCEEDED:
                return $this->handleChargeSucceeded($this->event, $response);
            case Event::PAYMENT_INTENT_CANCELED:
                return $this->handlePaymentIntentCancelled($request, $this->event, $response);
            default:
                $this->logger->warning(sprintf('Unsupported event type "%s"', $this->event->type));
                return $this->respond($response, new ActionPayload(204));
        }
    }

    private function handleChargeSucceeded(Event $event, Response $response): Response
    {
        /** @var Charge $charge */
        $charge = $event->data->object;

        $intentId = $charge->payment_intent;

        $this->entityManager->beginTransaction();

        /** @var Donation $donation */
        $donation = $this->donationRepository->findAndLockOneBy(['transactionId' => $intentId]);

        if (!$donation) {
            $this->logger->notice(sprintf('Donation not found with Payment Intent ID %s', $intentId));
            $this->entityManager->rollback();

            return $this->respond($response, new ActionPayload(204));
        }

        // For now we support the happy success path –
        // as this is the only event type we're handling right now besides refunds.
        if ($charge->status === 'succeeded') {
            $donation->setChargeId($charge->id);
            $donation->setTransferId($charge->transfer);

            $donation->setDonationStatus(DonationStatus::Collected);
            $donation->setCollectedAt(new \DateTime("@{$charge->created}"));

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

            $this->logger->info(sprintf(
                'Set donation %s Collected based on hook for charge ID %s',
                $donation->getUuid(),
                $charge->id,
            ));
        } else {
            $this->logger->error(sprintf(
                'Ignoring unsupported status %s for donation %s on charge ID %s',
                $charge->status,
                $donation->getUuid(),
                $charge->id,
            ));
            $this->entityManager->rollback();

            return $this->validationError($response, sprintf('Unsupported Status "%s"', $charge->status));
        }

        $this->entityManager->persist($donation);
        $this->entityManager->commit();

        // We log if this fails but don't worry the webhook-sending payment client
        // about it. We'll re-try sending the updated status to Salesforce in a future
        // batch sync.
        $this->donationRepository->push($donation, false); // Attempt immediate sync to Salesforce

        return $this->respondWithData($response, $charge);
    }

    /**
     * Treats closed lost disputes like a refund. Ignores closed won disputes (except an info
     * log) but acks the webhook with an HTTP 204.
     *
     * @link https://stripe.com/docs/issuing/purchases/disputes
     */
    private function handleChargeDisputeClosed(Event $event, Response $response): Response
    {
        /** @var Dispute $dispute */
        $dispute = $event->data->object;

        $intentId = $dispute->payment_intent;

        if ($dispute->status !== 'lost') {
            $this->logger->info(sprintf(
                'Dispute %s (reason: %s) closure for Payment Intent ID %s ignored as no updates needed for status %s',
                $dispute->id,
                $dispute->reason,
                $intentId,
                $dispute->status,
            ));
            return $this->respond($response, new ActionPayload(204));
        }

        $this->entityManager->beginTransaction();

        /** @var Donation $donation */
        $donation = $this->donationRepository->findAndLockOneBy(['transactionId' => $intentId]);

        if (!$donation) {
            $this->logger->notice(sprintf('Donation not found with Payment Intent ID %s', $intentId));
            $this->entityManager->rollback();

            return $this->respond($response, new ActionPayload(204));
        }

        if ($donation->getAmountFractionalIncTip() > $dispute->amount) {
            // Less than the original amount was returned/refunded, which we don't expect.
            $this->logger->error(sprintf(
                'Skipping unexpected dispute lost amount %s pence for donation %s based on Payment Intent ID %s',
                $dispute->amount, // int: pence / cents.
                $donation->getUuid(),
                $intentId,
            ));
            $this->entityManager->rollback();

            return $this->respond($response, new ActionPayload(204));
        }

        if ($dispute->amount > $donation->getAmountFractionalIncTip()) {
            // More than the original amount was returned/refunded, which we don't
            // *really* expect but are best to continue processing as a full refund
            // with a warning.
            $this->warnAboutOverRefund(
                'charge.dispute.closed (lost)',
                $donation,
                $dispute->amount,
                $dispute->currency,
            );
        }

        $this->logger->info(sprintf(
            'Marking donation %s refunded based on dispute %s (reason: %s) for Payment Intent ID %s',
            $donation->getUuid(),
            $dispute->id,
            $dispute->reason,
            $intentId,
        ));

        $refundDate = DateTimeImmutable::createFromFormat('U', (string)$this->event->created);
        assert($refundDate instanceof DateTimeImmutable);
        $donation->recordRefundAt($refundDate);
        $this->doPostMarkRefundedUpdates($donation, true);

        return $this->respondWithData($response, $event->data->object);
    }

    private function handleChargeRefunded(Event $event, Response $response): Response
    {
        /** @var Charge $charge */
        $charge = $event->data->object;
        $amountRefunded = $charge->amount_refunded; // int: pence.

        // Available status' (pending, succeeded, failed, canceled),
        // see: https://stripe.com/docs/api/refunds/object.
        // For now we support the successful refund path (inc. partial refund IF it's for the tip amount),
        // converting status to the one MatchBot + SF use.
        if ($charge->status !== 'succeeded') {
            return $this->validationError($response, sprintf('Unsupported Status "%s"', $charge->status));
        }

        $this->entityManager->beginTransaction();

        /** @var Donation $donation */
        $donation = $this->donationRepository->findOneBy(['chargeId' => $charge->id]);

        if (!$donation) {
            $this->logger->notice(sprintf('Donation not found with Charge ID %s', $charge->id));
            $this->entityManager->rollback();
            return $this->respond($response, new ActionPayload(204));
        }

        $isTipRefund = $donation->getTipAmountFractional() === $amountRefunded;
        $isFullRefund = $donation->getAmountFractionalIncTip() === $amountRefunded;
        // If things disagree about the original captured amount, this is still uncharted
        // territory and we should refrain from processing. If that matches but there is an
        // over-refund, we'll want to notify the team but we know we are best off handling it
        // like a simple refund from a donation status perspective.
        $isOverRefund = (
            $donation->getAmountFractionalIncTip() === $charge->amount_captured &&
            $amountRefunded > $donation->getAmountFractionalIncTip()
        );

        if ($isTipRefund) {
            $this->logger->info(sprintf(
                'Setting donation %s tip amount to £0 based on charge ID %s',
                $donation->getUuid(),
                $charge->id,
            ));
            $refundDate = DateTimeImmutable::createFromFormat('U', (string)$this->event->created);
            assert($refundDate instanceof DateTimeImmutable);
            $donation->setPartialRefundDate($refundDate);
            $donation->setTipAmount('0.00');
        } elseif ($isFullRefund) {
            $this->logger->info(sprintf(
                'Marking donation %s refunded based on charge ID %s',
                $donation->getUuid(),
                $charge->id,
            ));
            $refundDate = DateTimeImmutable::createFromFormat('U', (string)$this->event->created);
            assert($refundDate instanceof DateTimeImmutable);
            $donation->recordRefundAt($refundDate);
        } elseif ($isOverRefund) {
            $this->warnAboutOverRefund(
                'charge.refunded',
                $donation,
                $amountRefunded,
                $charge->currency,
            );

            $this->logger->info(sprintf(
                'Marking donation %s refunded (with extra) based on charge ID %s',
                $donation->getUuid(),
                $charge->id,
            ));
            $refundDate = DateTimeImmutable::createFromFormat('U', (string)$this->event->created);
            assert($refundDate instanceof DateTimeImmutable);
            $donation->recordRefundAt($refundDate);
        } else {
            $this->logger->error(sprintf(
                'Skipping unexpected partial non-tip refund amount %s pence for donation %s based on charge ID %s',
                $amountRefunded,
                $donation->getUuid(),
                $charge->id,
            ));
            $this->entityManager->rollback();
            return $this->respond($response, new ActionPayload(204));
        }

        $this->doPostMarkRefundedUpdates($donation, $isFullRefund || $isOverRefund);

        return $this->respondWithData($response, $charge);
    }

    private function handlePaymentIntentCancelled(Request $request, Event $event, Response $response): Response
    {
        /** @var PaymentIntent $paymentIntent */
        $paymentIntent = $event->data->object;
        $donation = $this->donationRepository->findOneBy(['transactionId' => $paymentIntent->id]);

        if ($donation === null) {
            return $this->respond($response, new ActionPayload(404));
        }

        if ($donation->getDonationStatus() !== DonationStatus::Cancelled) {
            $this->logger->info(sprintf(
                'Received Stripe cancellation request, Donation ID %s, payment intent ID %s',
                $donation->getId() ?? throw new \Exception("Missing ID on donation"),
                $paymentIntent->id,
            ));
        }

        try {
            $donation->cancel();
        } catch (\UnexpectedValueException) {
            return $this->respond($response, new ActionPayload(400));
        }

        return $this->respond($response, new ActionPayload(200));
    }

    /**
     * @param int $refundedOrDisputedAmount In small currency unit, e.g. pence or cents.
     */
    private function warnAboutOverRefund(
        string $eventType,
        Donation $donation,
        int $refundedOrDisputedAmount,
        string $refundedOrDisputedCurrencyCode,
    ): void {
        $detailsMessage = sprintf(
            'Over-refund detected for donation %s based on %s hook. Donation inc. tip was %s %s and refund or dispute was %s %s',
            $donation->getUuid(),
            $eventType,
            bcdiv((string) $donation->getAmountFractionalIncTip(),  '100', 2),
            $donation->getCurrencyCode(),
            bcdiv((string) $refundedOrDisputedAmount, '100', 2),
            strtoupper($refundedOrDisputedCurrencyCode),
        );
        $this->logger->warning($detailsMessage);

        $chatMessage = new ChatMessage('Over-refund detected');
        $options = (new SlackOptions())
            ->block((new SlackHeaderBlock(sprintf(
                '[%s] %s',
                (string) getenv('APP_ENV'),
                'Over-refund detected',
            ))))
            ->block((new SlackSectionBlock())->text($detailsMessage))
            ->iconEmoji(':o');
        $chatMessage->options($options);

        $this->chatter->send($chatMessage);
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

    /**
     * Called after updates set a donation status to Refunded *or* clear its tip amount
     * after a partial tip refund.
     *
     * Assumes it will be called only after starting a transaction pre-donation-select.
     *
     * @param Donation $donation
     * @param bool $isCoreDonationReversed Should be true for full refunds, over-refunds and disputes closed lost. False for tip refunds.
     */
    private function doPostMarkRefundedUpdates(Donation $donation, bool $isCoreDonationReversed): void
    {
        $this->entityManager->persist($donation);
        $this->entityManager->flush();
        $this->entityManager->commit();

        // Release match funds only if the donation was matched and
        // the refunded amount is equal to the local txn amount.
        if (
            $isCoreDonationReversed &&
            $donation->getDonationStatus()->isReversed() &&
            $donation->getCampaign()->isMatched()
        ) {
            $this->donationRepository->releaseMatchFunds($donation);
        }

        $this->donationRepository->push($donation, false); // Attempt immediate sync to Salesforce
    }
}
