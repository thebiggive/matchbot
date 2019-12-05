<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Actions\ActionError;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Auth\Token;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\HttpModels\DonationCreatedResponse;
use MatchBot\Domain\DomainException\DomainLockContentionException;
use MatchBot\Domain\DonationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\SerializerInterface;

class Create extends Action
{
    /** @var DonationRepository */
    private $donationRepository;
    /** @var EntityManagerInterface */
    private $entityManager;
    /** @var SerializerInterface */
    private $serializer;

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

    /**
     * @return Response
     */
    protected function action(): Response
    {
        /** @var DonationCreate $donationData */
        try {
            $donationData = $this->serializer->deserialize(
                $this->request->getBody(),
                DonationCreate::class,
                'json'
            );
        } catch (UnexpectedValueException $exception) { // This is the Serializer one, not the global one
            $message = 'Donation Create data deserialise';
            $exceptionType = get_class($exception);
            $this->logger->warning("$message: $exceptionType - {$exception->getMessage()}");
            $this->logger->info("Donation Create non-serialisable payload was: {$this->request->getBody()}");
            $error = new ActionError(ActionError::BAD_REQUEST, $message);

            return $this->respond(new ActionPayload(400, null, $error));
        }

        try {
            $donation = $this->donationRepository->buildFromApiRequest($donationData);
        } catch (\UnexpectedValueException $exception) {
            $message = 'Donation Create data initial model load';
            $this->logger->warning($message . ': ' . $exception->getMessage());
            $error = new ActionError(ActionError::BAD_REQUEST, $message);

            return $this->respond(new ActionPayload(400, null, $error));
        }

        if (!$donation->getCampaign()->isOpen()) {
            $message = "Campaign {$donation->getCampaign()->getSalesforceId()} is not open";
            $this->logger->warning($message);
            $error = new ActionError(ActionError::BAD_REQUEST, $message);

            return $this->respond(new ActionPayload(400, null, $error));
        }

        try {
            if ($donation->getCampaign()->isMatched()) {
                // This implicitly calls @prePersist on the Donation, so is part of the try{...}
                $this->donationRepository->allocateMatchFunds($donation);
            }

            $this->entityManager->persist($donation);
            $this->entityManager->flush();
        } catch (\UnexpectedValueException $exception) {
            $message = 'Donation Create data failed validation';
            $this->logger->warning($message);
            $error = new ActionError(ActionError::BAD_REQUEST, $message);

            return $this->respond(new ActionPayload(400, null, $error));
        } catch (DomainLockContentionException $exception) {
            $error = new ActionError(ActionError::SERVER_ERROR, 'Fund resource locked');

            return $this->respond(new ActionPayload(503, null, $error));
        }

        $response = new DonationCreatedResponse();
        $response->donation = $donation->toApiModel();
        $response->jwt = Token::create($donation->getUuid());

        // Attempt immediate sync. Buffered for a future batch sync if the SF call fails.
        $this->donationRepository->push($donation, true);

        return $this->respondWithData($response);
    }
}
