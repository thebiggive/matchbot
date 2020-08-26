<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Hooks;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Stripe\Event;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * @return Response
 */
class StripeUpdate extends Action
{
    private DonationRepository $donationRepository;
    private EntityManagerInterface $entityManager;
    private string $webhookSecret;

    public function __construct(
        ContainerInterface $container,
        DonationRepository $donationRepository,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        SerializerInterface $serializer
    ) {
        $this->donationRepository = $donationRepository;
        $this->entityManager = $entityManager;
        $this->serializer = $serializer;
        // As `settings` is just an array for now, I think we have to inject Container to do this.
        $this->webhookSecret = $container->get('settings')['stripe']['webhookSecret'];

        parent::__construct($logger);
    }

    /**
     * @return Response
     * @throws DomainRecordNotFoundException
     * @throws HttpBadRequestException
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
            return $this->validationError('Invalid Payload');
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return $this->validationError('Invalid Signature');
        }

        if ($event instanceof Event) {
            switch ($event->type) {
                case 'charge.succeeded':
                    $this->handleChargeSucceeded($event);
                    break;
                default:
                    $this->logger->info('Unsupported Action');
                    return $this->respond(new ActionPayload(204));
            }
        } else {
            return $this->validationError('Invalid Instance');
        }

        return $this->respondWithData($event->data->object);
    }

    public function handleChargeSucceeded(Event $event): Response
    {
        /** @var Donation $donation */
        $donation = $this->donationRepository->findOneBy(['transactionId' => $event->data->object->payment_intent]);

        if (!$donation) {
            throw new DomainRecordNotFoundException('Donation not found');
        }
        
        // For now we support the happy success path,
        // as this is the only event type we're handling right now,
        // convert status to the one SF uses.
        if ($event->data->object->status === 'succeeded') {
            $donation->setChargeId($event->data->object->id);
            $donation->setDonationStatus('Collected');
        } else {
            return $this->validationError('Unsupported Status');
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
}
