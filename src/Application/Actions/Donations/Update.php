<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use MatchBot\Application\Actions\Action;
use MatchBot\Application\Actions\ActionError;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\HttpModels;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Apply a donor-authorised PUT action to update an existing donation. The purpose
 * of the update can be to cancel the donation, or to add more details to it.
 */
class Update extends Action
{
    private DonationRepository $donationRepository;
    private SerializerInterface $serializer;

    public function __construct(
        DonationRepository $donationRepository,
        LoggerInterface $logger,
        SerializerInterface $serializer
    ) {
        $this->donationRepository = $donationRepository;
        $this->serializer = $serializer;

        parent::__construct($logger);
    }

    /**
     * @return Response
     * @throws DomainRecordNotFoundException
     */
    protected function action(): Response
    {
        if (empty($this->args['donationId'])) { // When MatchBot made a donation, this is now a UUID
            throw new DomainRecordNotFoundException('Missing donation ID');
        }

        /** @var Donation $donation */
        $donation = $this->donationRepository->findOneBy(['uuid' => $this->args['donationId']]);

        if (!$donation) {
            throw new DomainRecordNotFoundException('Donation not found');
        }

        try {
            /** @var HttpModels\Donation $donationData */
            $donationData = $this->serializer->deserialize(
                $this->request->getBody(),
                HttpModels\Donation::class,
                'json'
            );
        } catch (UnexpectedValueException $exception) { // This is the Serializer one, not the global one
            $message = 'Donation Cancel data deserialise error';
            $exceptionType = get_class($exception);
            $this->logger->warning("$message: $exceptionType - {$exception->getMessage()}");
            $this->logger->info("Donation Cancel non-serialisable payload was: {$this->request->getBody()}");
            $error = new ActionError(ActionError::BAD_REQUEST, $message);

            return $this->respond(new ActionPayload(400, null, $error));
        }

        if (!isset($donationData->status)) {
            $this->logger->warning(
                "Donation ID {$this->args['donationId']} could not be updated with missing status"
            );
            $error = new ActionError(ActionError::BAD_REQUEST, 'New status is required');

            return $this->respond(new ActionPayload(400, null, $error));
        }

        if ($donationData->status !== 'Cancelled') {
            $this->logger->warning(
                "Donation ID {$this->args['donationId']} could not be set to status {$donationData->status}"
            );
            $error = new ActionError(ActionError::BAD_REQUEST, 'Only cancellations supported');

            return $this->respond(new ActionPayload(400, null, $error));
        }

        if ($donation->getDonationStatus() === 'Cancelled') {
            $this->logger->info("Donation ID {$this->args['donationId']} was already Cancelled");
            return $this->respondWithData($donation->toApiModel(false));
        }

        if ($donation->isSuccessful()) {
            // If a donor uses browser back before loading the thank you page, it is possible for them to get
            // a Cancel dialog and send a cancellation attempt to this endpoint after finishing the donation.
            $this->logger->warning(
                "Donation ID {$this->args['donationId']} could not be cancelled as {$donation->getDonationStatus()}"
            );
            $error = new ActionError(ActionError::BAD_REQUEST, 'Donation already finalised');

            return $this->respond(new ActionPayload(400, null, $error));
        }

        $this->logger->info("Donor cancelled ID {$this->args['donationId']}");

        $donation->setDonationStatus('Cancelled');
        if ($donation->getCampaign()->isMatched()) {
            $this->donationRepository->releaseMatchFunds($donation);
        }

        // We log if this fails but don't worry the client about it. We'll just re-try
        // sending the updated status to Salesforce in a future batch sync.
        $this->donationRepository->push($donation, false);

        return $this->respondWithData($donation->toApiModel(false));
    }
}
