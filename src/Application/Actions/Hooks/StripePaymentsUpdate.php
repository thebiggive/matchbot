<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Hooks;

use Assert\Assertion;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Messenger\DonationStateUpdated;
use MatchBot\Application\Notifier\StripeChatterInterface;
use MatchBot\Domain\Currency;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationFundsNotifier;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\Money;
use MatchBot\Domain\StripeCustomerId;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Stripe\Card;
use Stripe\Charge;
use Stripe\Dispute;
use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackHeaderBlock;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackSectionBlock;
use Symfony\Component\Notifier\Bridge\Slack\SlackOptions;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Notifier\Message\ChatMessage;

/**
 * Handles some events from Stripe's `charge` and `payment_intent` objects from a Stripe Direct webhook. See
 * self::action for details.
 *
 * @return Response
 */
class StripePaymentsUpdate extends Stripe
{
    private ChatterInterface $chatter;

    public function __construct(
        protected DonationRepository $donationRepository,
        private DonorAccountRepository $donorAccountRepository,
        protected EntityManagerInterface $entityManager,
        protected StripeClient $stripeClient,
        private DonationFundsNotifier $donationFundsNotifier,
        ContainerInterface $container,
        LoggerInterface $logger,
        private RoutableMessageBus $bus,
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

        $event = $this->event ?? throw new \RuntimeException("Stripe Event not set");

        $type = $event->type;
        $this->logger->info(sprintf('Received Stripe account event type "%s"', $type));

        switch ($type) {
            case Event::CHARGE_DISPUTE_CLOSED:
                return $this->handleChargeDisputeClosed($event, $response);
            case Event::CHARGE_REFUNDED:
                return $this->handleChargeRefunded($event, $response);
            case Event::CHARGE_SUCCEEDED:
                return $this->handleChargeSucceeded($event, $response);
            case Event::PAYMENT_INTENT_CANCELED:
                return $this->handlePaymentIntentCancelled($event, $response);
            case Event::CUSTOMER_CASH_BALANCE_TRANSACTION_CREATED:
                return $this->handleCashBalanceUpdate($event, $response);
            default:
                $this->logger->warning(sprintf('Unsupported event type "%s"', $type));
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
            /**
             * @var array|Card|null $card
             */
            $card = $charge->payment_method_details?->toArray()['card'] ?? null;
            if (is_array($card)) {
                /** @var Card $card */
                $card = (object)$card;
            }

            $cardBrand = $card?->brand;
            $cardCountry = $card?->country;
            $balanceTransaction = (string) $charge->balance_transaction;

            // To give *simulated* webhooks, for Donation API-only load tests, an easy way to complete
            // without crashing, we support skipping the original fee derivation by omitting
            // `balance_transaction`. Real stripe charge.succeeded webhooks should always have
            // an associated Balance Transaction.
            if (!empty($balanceTransaction)) {
                $originalFeeFractional = $this->getOriginalFeeFractional(
                    $balanceTransaction,
                    $donation->getCurrencyCode(),
                );
            } else {
                $originalFeeFractional = $donation->getOriginalPspFee();
            }

            $donation->collectFromStripeCharge(
                chargeId: $charge->id,
                transferId: (string)$charge->transfer,
                cardBrand: $cardBrand,
                cardCountry: $cardCountry,
                originalFeeFractional: (string)$originalFeeFractional,
                chargeCreationTimestamp: $charge->created,
            );

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
        $this->entityManager->flush();
        $this->bus->dispatch(new Envelope(DonationStateUpdated::fromDonation($donation)));


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

        $refundDate = DateTimeImmutable::createFromFormat('U', (string)$event->created);
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
            $refundDate = DateTimeImmutable::createFromFormat('U', (string)$event->created);
            assert($refundDate instanceof DateTimeImmutable);
            $donation->setPartialRefundDate($refundDate);
            $donation->setTipAmount('0.00');
        } elseif ($isFullRefund) {
            $this->logger->info(sprintf(
                'Marking donation %s refunded based on charge ID %s',
                $donation->getUuid(),
                $charge->id,
            ));
            $refundDate = DateTimeImmutable::createFromFormat('U', (string)$event->created);
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
            $refundDate = DateTimeImmutable::createFromFormat('U', (string)$event->created);
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

    private function handlePaymentIntentCancelled(Event $event, Response $response): Response
    {
        $paymentIntent = $event->data->object;
        \assert($paymentIntent instanceof PaymentIntent);

        $donation = $this->donationRepository->findOneBy(['transactionId' => $paymentIntent->id]);

        if ($donation === null) {
            if (getenv('APP_ENV') !== 'production') {
                // outside of production we expect Stripe to combine things from multiple test environments
                // (staging & regtest) into one, so we may get irrelevant cancellation requests. We don't want
                // them logged as errors.
                return $this->respond($response, new ActionPayload(204));
            }
            return $this->respond($response, new ActionPayload(404));
        }

        if (DonationStatus::Cancelled === $donation->getDonationStatus()) {
            return $this->respond($response, new ActionPayload(204, ['reason' => 'Donation is already cancelled']));
        }

        $this->logger->info(sprintf(
            'Received Stripe cancellation request, Donation UUID %s, payment intent ID %s',
            $donation->getUuid(),
            $paymentIntent->id,
        ));

        try {
            $donation->cancel();
        } catch (\UnexpectedValueException) {
            return $this->respond($response, new ActionPayload(400));
        }

        $this->entityManager->flush();
        $this->bus->dispatch(new Envelope(DonationStateUpdated::fromDonation($donation)));

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
            'Over-refund detected for donation %s based on %s hook. Donation inc. tip was %s %s ' .
                'and refund or dispute was %s %s',
            $donation->getUuid(),
            $eventType,
            bcdiv((string) $donation->getAmountFractionalIncTip(), '100', 2),
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
     * @param bool $isCoreDonationReversed  Should be true for full refunds, over-refunds and disputes
     *                                      closed lost. False for tip refunds.
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

        $this->entityManager->flush();
        $this->bus->dispatch(new Envelope(DonationStateUpdated::fromDonation($donation)));
    }

    private function handleCashBalanceUpdate(Event $event, Response $response): Response
    {
        Assertion::eq('customer_cash_balance_transaction.created', $event->type);

        /** @var array{customer: string, currency: string, net_amount: int, ending_balance: int, type: string} $webhookObject */
        $webhookObject = $event->data->toArray()['object'];

        $stripeAccountId = StripeCustomerId::of($webhookObject['customer']);

        if ($webhookObject['type'] !== 'funded') {
            return $this->respond($response, new ActionPayload(200));
        }

        $donorAccount = $this->donorAccountRepository->findByStripeIdOrNull($stripeAccountId);
        $currency = Currency::fromIsoCode($webhookObject['currency']);
        $transferAmount = Money::fromPence($webhookObject['net_amount'], $currency);
        $endingBalance = Money::fromPence($webhookObject['ending_balance'], $currency);

        $app_env = getenv('APP_ENV');
        if ($donorAccount === null) {
            \assert(is_string($app_env));
            if ($app_env !== "regression" && $app_env !== "staging") {
                // We expect the regression and staging environments to generate irrelevent webhooks to each other,
                // so we don't need to notify Slack about the unexpected webhooks there.
                $this->chatter->send(new ChatMessage(
                    "$app_env: Cash Balance update received for unknown account: " . $stripeAccountId->stripeCustomerId
                ));
            }

            return $this->respond($response, new ActionPayload(200));
        }

        $this->donationFundsNotifier->notifyRecieptOfAccountFunds($donorAccount, $transferAmount, $endingBalance);

        $donorAccountId = $donorAccount->getId();
        \assert(is_int($donorAccountId)); // must be an int since it was persisted before.
        $this->logger->info(
            'Sent notification of receipt of account funds for Stripe Account: ' . $stripeAccountId->stripeCustomerId .
            ", transfer Amount" . $transferAmount->format() .
            ", new balance" . $endingBalance->format() .
            ", DonorAccount #" . $donorAccountId
        );

        return $this->respond($response, new ActionPayload(200));
    }
}
