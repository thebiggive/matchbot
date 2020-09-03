<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Hooks;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Stripe\Event;
use Stripe\StripeClient;

/**
 * @return Response
 */
class StripeUpdate extends Action
{
    private DonationRepository $donationRepository;
    private EntityManagerInterface $entityManager;
    private StripeClient $stripeClient;
    private string $webhookSecret;

    public function __construct(
        ContainerInterface $container,
        DonationRepository $donationRepository,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        StripeClient $stripeClient
    ) {
        $this->donationRepository = $donationRepository;
        $this->entityManager = $entityManager;
        $this->stripeClient = $stripeClient;
        // As `settings` is just an array for now, I think we have to inject Container to do this.
        $this->apiKey = $container->get('settings')['stripe']['apiKey'];
        $this->webhookSecret = $container->get('settings')['stripe']['webhookSecret'];

        parent::__construct($logger);
    }

    /**
     * @return Response
     */
    protected function action(): Response
    {
        try {
            $event = \Stripe\Webhook::constructEvent(
                $this->request->getBody(),
                $this->request->getHeaderLine('stripe-signature'),
                $this->webhookSecret
            );
        } catch (\UnexpectedValueException $e) {
            return $this->validationError("Invalid Payload: {$e->getMessage()}", 'Invalid Payload');
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return $this->validationError('Invalid Signature');
        }

        if ($event instanceof Event) {
            switch ($event->type) {
                case 'charge.succeeded':
                    return $this->handleChargeSucceeded($event);
                case 'payout.paid':
                    return $this->handlePayoutPaid($event);
                default:
                    $this->logger->info('Unsupported Action');
                    return $this->respond(new ActionPayload(204));
            }
        }

        return $this->validationError('Invalid event');
    }

    private function handleChargeSucceeded(Event $event): Response
    {
        $intentId = $event->data->object->payment_intent;

        /** @var Donation $donation */
        $donation = $this->donationRepository->findOneBy(['transactionId' => $intentId]);

        if (!$donation) {
            $this->logger->info(sprintf('Donation not found with Payment Intent ID %s', $intentId));
            return $this->respond(new ActionPayload(204));
        }

        // For now we support the happy success path,
        // as this is the only event type we're handling right now,
        // convert status to the one SF uses.
        if ($event->data->object->status === 'succeeded') {
            $donation->setChargeId($event->data->object->id);
            $donation->setDonationStatus('Collected');
        } else {
            return $this->validationError(sprintf('Unsupported Status "%s"', $event->data->object->status));
        }

        if ($donation->isReversed() && $event->data->object->metadata->matchedAmount > 0) {
            $this->donationRepository->releaseMatchFunds($donation);
        }

        $this->entityManager->persist($donation);

        // We log if this fails but don't worry the webhook-sending payment client
        // about it. We'll re-try sending the updated status to Salesforce in a future
        // batch sync.
        $this->donationRepository->push($donation, false); // Attempt immediate sync to Salesforce

        return $this->respondWithData($event->data->object);
    }

    public function handlePayoutPaid(Event $event): Response
    {
        $payoutId = $event->data->object->id;

        $this->logger->info(sprintf('Getting all charges related to Payout ID: %s', $payoutId));

        $hasMore = true;
        $lastBalanceTransactionId = null;
        $paidChargeIds = [];
        $attributes = [
            'limit' => 100,
            'payout' => $payoutId,
            'type' => 'charge',
        ];

        while ($hasMore) {
            $balanceTransactions = $this->stripeClient->balanceTransactions->all($attributes);

            foreach ($balanceTransactions->data as $balanceTransaction) {
                $paidChargeIds[] = $balanceTransaction->source;
                $lastBalanceTransactionId = $balanceTransaction->id;
            }

            $hasMore = $balanceTransactions->has_more;

            // We get a Stripe exception if we start this with a null or empty value,
            // so we only include this once we've iterated the first time and captured
            // a transaciton Id.
            if ($lastBalanceTransactionId !== null) {
                $attributes['start_after'] = $lastBalanceTransactionId;
            }
        }
        $this->logger->info(sprintf('Getting all paid Charge IDs complete, found: %s', count($paidChargeIds)));

        if (count($paidChargeIds) > 0) {
            $count = 0;

            foreach ($paidChargeIds as $chargeId) {
                /** @var Donation $donation */
                $donation = $this->donationRepository->findOneBy(['chargeId' => $chargeId]);

                // If a donation was not found, then it's most likely from a different
                // sandbox and therefore we info log this and respond with 204.
                if (!$donation) {
                    $this->logger->info(sprintf('Donation not found with Charge ID %s', $chargeId));
                    return $this->respond(new ActionPayload(204));
                }

                if ($donation->getDonationStatus() === 'Collected') {
                    // We're confident to set donation status to paid because this
                    // method is called only when Stripe event `payout.paid` is received.
                    $donation->setDonationStatus('Paid');

                    $this->entityManager->persist($donation);
                    $this->donationRepository->push($donation, false);

                    $count++;
                } elseif (
                    $donation->getDonationStatus() !== 'Collected' ||
                    $donation->getDonationStatus() !== 'Paid'
                ) {
                    $this->logger->error(sprintf('Unexpected donation status found for Charge ID %s', $chargeId));
                    return $this->respond(new ActionPayload(400));
                }
            }

            $this->logger->info(sprintf('Acknowledging paid donations complete, persisted: %s', $count));
            return $this->respondWithData($event->data->object);
        }
    }
}
