<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Hooks;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Actions\ActionError;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\HttpModels;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Symfony\Component\Serializer\SerializerInterface;
use Stripe\Event as StripeEvent;

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
        $webhookSecret = getenv('STRIPE_WEBHOOK_SECRET');

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $signature, $webhookSecret);
        } catch (\UnexpectedValueException $e) {
            $logMessage = 'Invalid Payload';
            $this->logger->warning($logMessage);
            $error = new ActionError(ActionError::BAD_REQUEST, null ?? $logMessage);
            return $this->respond(new ActionPayload(400, null, $error));
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            $logMessage = 'Invalid Signature';
            $this->logger->warning($logMessage);
            $error = new ActionError(ActionError::BAD_REQUEST, null ?? $logMessage);
            return $this->respond(new ActionPayload(400, null, $error));
        }

        /** @var Donation $donation */
        $donation = $this->donationRepository->findOneBy(['transactionId' => $event->data->object->id]);

        if ($event instanceof StripeEvent && $donation) {
            // Handle the event
            switch ($event->type) {
                case 'payment_intent.succeeded':
                    $this->handlePaymentIntentSucceeded($event, $donation);
                    break;
                case 'payment_intent.created':
                    $logMessage = 'Unsupported Action';
                    $this->logger->warning($logMessage);
                    $error = new ActionError(ActionError::BAD_REQUEST, null ?? $logMessage);
                    return $this->respond(new ActionPayload(400, null, $error));
                default:
                    $logMessage = 'Unsupported Action';
                    $this->logger->warning($logMessage);
                    $error = new ActionError(ActionError::BAD_REQUEST, null ?? $logMessage);
                    return $this->respond(new ActionPayload(400, null, $error));
            }
        } else {
            $logMessage = 'No Content';
            $this->logger->warning($logMessage);
            $error = new ActionError(ActionError::BAD_REQUEST, null ?? $logMessage);
            return $this->respond(new ActionPayload(204, null, $error));
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
            !isset($event->data->metadata->coreDonationGiftAid, $event->data->metadata->optInTbgEmail) ||
            empty($event->data->object->id));
        if ($missingRequiredField) {
            $message = 'Hook missing required values';
            $this->logger->warning("Donation ID {$event->data->object->id}: {$message}");
            $error = new ActionError(ActionError::BAD_REQUEST, $message);

            return $this->respond(new ActionPayload(400, null, $error));
        }

        $donation->setDonationStatus($event->status);
        $donation->setDonorBillingAddress($event->data->object->billing_details->address->postal_code);
        $donation->setDonorCountryCode($event->data->object->billing_details->address->country);
        $donation->setDonorEmailAddress($event->data->object->billing_details->email);
        $donation->setDonorFirstName($event->data->object->billing_details->name);
        $donation->setGiftAid($event->data->metadata->coreDonationGiftAid);
        $donation->setTbgComms($event->data->metadata->optInTbgEmail);
        $donation->setTransactionId($event->data->object->id);

        if (isset($event->data->metadata->tipAmount)) {
            $donation->setTipAmount((string) $event->data->metadata->tipAmount);
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
