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
 * Handle charge.succeeded and charge.refunded events from a Stripe account webhook.
 *
 * @return Response
 */
class StripeChargeUpdate extends Action
{
    private DonationRepository $donationRepository;
    private EntityManagerInterface $entityManager;
    private StripeClient $stripeClient;
    private string $accountWebhookSecret;

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
        $this->accountWebhookSecret = $container->get('settings')['stripe']['accountWebhookSecret'];

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
                $this->accountWebhookSecret
            );
        } catch (\UnexpectedValueException $e) {
            return $this->validationError("Invalid Payload: {$e->getMessage()}", 'Invalid Payload');
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return $this->validationError('Invalid Signature');
        }

        if (!($event instanceof Event)) {
            return $this->validationError('Invalid event');
        }

        $this->logger->info(sprintf('Received Stripe account event type "%s"', $event->type));

        if (!$event->livemode && getenv('APP_ENV') === 'production') {
            $this->logger->warning(sprintf('Skipping non-live %s webhook in Production', $event->type));
            return $this->respond(new ActionPayload(204));
        }

        switch ($event->type) {
            case 'charge.refunded':
                return $this->handleChargeRefunded($event);
            case 'charge.succeeded':
                return $this->handleChargeSucceeded($event);
            default:
                $this->logger->warning(sprintf('Unsupported event type "%s"', $event->type));
                return $this->respond(new ActionPayload(204));
        }
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

    private function handleChargeRefunded(Event $event): Response
    {
        $chargeId = $event->data->object->id;
        $amountRefunded = $event->data->object->amount_refunded;

        /** @var Donation $donation */
        $donation = $this->donationRepository->findOneBy(['chargeId' => $chargeId]);

        if (!$donation) {
            $this->logger->info(sprintf('Donation not found with Charge ID %s', $chargeId));
            return $this->respond(new ActionPayload(204));
        }

        // Available status' (pending, succeeded, failed, canceled),
        // see: https://stripe.com/docs/api/refunds/object.
        // For now we support the happy success path,
        // convert status to the one SF uses.
        if ($event->data->object->status === 'succeeded') {
            $donation->setChargeId($event->data->object->id);
            $donation->setDonationStatus('Refunded');
        } else {
            return $this->validationError(sprintf('Unsupported Status "%s"', $event->data->object->status));
        }

        // Release match funds only if the donation was matched and
        // the refunded amount is equal to the local txn amount.
        // We multiply local donation amount by 100 to match Stripes calculations.
        if (
            $donation->isReversed()
            && $donation->getAmountInPenceIncTip() === $amountRefunded
            && $donation->getCampaign()->isMatched()
        ) {
            $this->donationRepository->releaseMatchFunds($donation);
        }

        $this->entityManager->persist($donation);

        $this->donationRepository->push($donation, false); // Attempt immediate sync to Salesforce

        return $this->respondWithData($event->data->object);
    }
}
