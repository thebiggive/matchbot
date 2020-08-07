<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Hooks;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Actions\ActionError;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Stripe\Event as StripeEvent;

/**
 * @return Response
 */
class StripeUpdate extends Action
{
    private DonationRepository $donationRepository;
    private EntityManagerInterface $entityManager;
    private SerializerInterface $serializer;

    public function __construct(
        DonationRepository $donationRepository,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        SerializerInterface $serializer
    ) {
        $this->donationRepository = $donationRepository;
        $this->entityManager = $entityManager;
        $this->serializer = $serializer;

        parent::__construct($logger);
    }

    protected function action(): Response
    {
        $payload = $this->request->getBody();
        $signature = $this->request->getHeaderLine('stripe-signature');
        $webhookSecret = getenv('STRIPE_WEBHOOK_SIGNING_SECRET');

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $signature, $webhookSecret);
        } catch (\UnexpectedValueException $e) {
            return $this->validationError("Invalid Payload", null);
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return $this->validationError("Invalid Signature", null);
        }

        if ($event instanceof StripeEvent) {
            /** @var Donation $donation */
            $donation = $this->donationRepository->findOneBy(['transactionId' => $event->data->object->id]);

            if ($donation) {
                switch ($event->type) {
                    case 'payment_intent.succeeded':
                        $this->handlePaymentIntentSucceeded($event, $donation);
                        break;
                    default:
                        return $this->validationError("Unsupported Action", null);
                }
            } else {
                $logMessage = 'No Content';
                $this->logger->warning($logMessage);
                $error = new ActionError(ActionError::BAD_REQUEST, null ?? $logMessage);
                return $this->respond(new ActionPayload(204, null, $error));
            }
        } else {
            return $this->validationError("Invalid Instance", null);
        }

        return $this->respondWithData($event->data->object);
    }

    public function handlePaymentIntentSucceeded(StripeEvent $event, $donation): Response
    {
        $missingRequiredField = (empty($event->status) ||
            empty($event->data->object->billing_details->address->postal_code) ||
            empty($event->data->object->billing_details->address->country) ||
            empty($event->data->object->billing_details->email) ||
            empty($event->data->object->billing_details->name) ||
            !isset($event->data->metadata->coreDonationGiftAid) ||
            !isset($event->data->metadata->optInTbgEmail) ||
            !isset($event->data->metadata->tipAmount) ||
            empty($event->data->object->id));

        if ($missingRequiredField) {
            return $this->validationError("Hook missing required values", null);
        }

        $donation->setTransactionId($event->id);

        // For now we support the happy success path,
        // as this is the only event type we're handling right now,
        // convert status to the one SF uses.
        if ($event->status == 'succeeded') {
            $donation->setDonationStatus('Collected');
        } else {
            return $this->validationError("Unsupported Status", null);
        }

        if ($donation->isReversed() && $event->data->metadata->matchedAmount > 0) {
            $this->donationRepository->releaseMatchFunds($donation);
        }

        $this->entityManager->persist($donation);

        // We log if this fails but don't worry the webhook-sending payment client
        // about it. We'll re-try sending the updated status to Salesforce in a future
        // batch sync.
        $this->donationRepository->push($donation, false); // Attempt immediate sync to Salesforce

        return $this->respondWithData($event->data->object);
    }
}
