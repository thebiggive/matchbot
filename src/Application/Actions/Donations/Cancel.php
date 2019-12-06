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
use Symfony\Component\Serializer\SerializerInterface;

class Cancel extends Action
{
    /** @var DonationRepository */
    private $donationRepository;
    /** @var SerializerInterface */
    private $serializer;

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

        /** @var HttpModels\Donation $donationData */
        $donationData = $this->serializer->deserialize(
            $this->request->getBody(),
            HttpModels\Donation::class,
            'json'
        );

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
