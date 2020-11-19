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
 * Handle payout.paid and payout.failed events from a Stripe Connect app webhook.
 *
 * @return Response
 */
class StripePayoutUpdate extends Action
{
    private DonationRepository $donationRepository;
    private EntityManagerInterface $entityManager;
    private StripeClient $stripeClient;
    private string $connectAppWebhookSecret;

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
        $this->connectAppWebhookSecret = $container->get('settings')['stripe']['connectAppWebhookSecret'];

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
                $this->connectAppWebhookSecret
            );
        } catch (\UnexpectedValueException $e) {
            return $this->validationError("Invalid Payload: {$e->getMessage()}", 'Invalid Payload');
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return $this->validationError('Invalid Signature');
        }

        if (!($event instanceof Event)) {
            return $this->validationError('Invalid event');
        }

        $this->logger->info(sprintf('Received Stripe Connect app event type "%s"', $event->type));

        if (!$event->livemode && getenv('APP_ENV') === 'production') {
            $this->logger->warning(sprintf('Skipping non-live %s webhook in Production', $event->type));
            return $this->respond(new ActionPayload(204));
        }

        switch ($event->type) {
            case 'payout.paid':
                return $this->handlePayoutPaid($event);
            case 'payout.failed':
                $this->logger->error(sprintf('payout.failed for ID %s', $event->data->object->id));
                return $this->respond(new ActionPayload(200));
            default:
                $this->logger->warning(sprintf('Unsupported event type "%s"', $event->type));
                return $this->respond(new ActionPayload(204));
        }
    }

    private function handlePayoutPaid(Event $event): Response
    {
        $count = 0;
        $payoutId = $event->data->object->id;

        $this->logger->info(sprintf('Payout: Getting all charges related to Payout ID: %s', $payoutId));

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
        $this->logger->info(sprintf('Payout: Getting all paid Charge IDs complete, found: %s', count($paidChargeIds)));

        if (count($paidChargeIds) > 0) {
            foreach ($paidChargeIds as $chargeId) {
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
}
