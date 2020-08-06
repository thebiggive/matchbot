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
        $webhookSecret = 'whsec_V4mcupoQsVuKmP8PuTMIAHf83E8LBRHt'; // TODO replace with one supplied in Infra

        try {
            $event = \Stripe\Webhook::constructEvent($payload, $signature, $webhookSecret);
        } catch(\UnexpectedValueException $e) {
            $logMessage = 'Invalid Payload';
            $this->logger->warning($logMessage);
            $error = new ActionError(ActionError::BAD_REQUEST, null ?? $logMessage);
            return $this->respond(new ActionPayload(400, null, $error));
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
            $logMessage = 'Invalid Signature';
            $this->logger->warning($logMessage);
            $error = new ActionError(ActionError::BAD_REQUEST, null ?? $logMessage);
            return $this->respond(new ActionPayload(400, null, $error));
        }

        $payload = new ActionPayload(200, $event->data->object);

        /** @var Donation $donation */
        $donation = $this->donationRepository->findOneBy(['transactionId' => $event->data->object->id]);

        if (!$donation) {
            throw new DomainRecordNotFoundException('Donation not found');
        }

        return $this->respondWithData($event->data->object);
    }
}
