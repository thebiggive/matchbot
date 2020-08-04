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
            $message = 'Donation Update data deserialise error';
            $exceptionType = get_class($exception);
            $this->logger->warning("$message: $exceptionType - {$exception->getMessage()}");
            $this->logger->info("Donation Update non-serialisable payload was: {$this->request->getBody()}");
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

        if ($donationData->status === 'Cancelled') {
            return $this->cancel($donation);
        }

        if ($donationData->status !== $donation->getDonationStatus()) {
            $this->logger->warning(
                "Donation ID {$this->args['donationId']} could not be set to status {$donationData->status}"
            );
            $error = new ActionError(ActionError::BAD_REQUEST, 'Status update is only supported for cancellation');

            return $this->respond(new ActionPayload(400, null, $error));
        }

        return $this->addData($donation, $donationData);
    }

    private function addData(Donation $donation, HttpModels\Donation $donationData): Response
    {
        // If the app tries to PUT with a different amount, something has gone very wrong and we should
        // explicitly fail instead of ignoring that field.
        if ($donation->getAmount() !== (string) $donationData->donationAmount) {
            $this->logger->warning(
                "Donation ID {$this->args['donationId']} amount did not match"
            );
            $error = new ActionError(ActionError::BAD_REQUEST, 'Amount updates are not supported');

            return $this->respond(new ActionPayload(400, null, $error));
        }

        // These two fields are currently set up early in the journey, but are harmless and more flexible
        // to support setting later. The frontend will probably leave these set and do a no-op update
        // when it makes the PUT call.
        if (isset($donationData->countryCode)) {
            $donation->setDonorCountryCode($donationData->countryCode);
        }
        if (isset($donationData->tipAmount)) {
            $donation->setTipAmount((string) $donationData->tipAmount);
        }

        // All calls using the new two-step approach should set all the remaining values in this
        // method every time they `addData()`.
        $donation->setGiftAid($donationData->giftAid);
        $donation->setTipGiftAid($donationData->tipGiftAid ?? $donationData->giftAid);
        $donation->setDonorHomeAddressLine1($donationData->homeAddress);
        $donation->setDonorHomePostcode($donationData->homePostcode);
        $donation->setDonorFirstName($donationData->firstName);
        $donation->setDonorLastName($donationData->lastName);
        $donation->setDonorEmailAddress($donationData->emailAddress);
        $donation->setTbgComms($donationData->optInTbgEmail);
        $donation->setCharityComms($donationData->optInCharityEmail);
        $donation->setDonorBillingAddress($donationData->billingPostalAddress);

        return $this->respondWithData($donation->toApiModel(false));
    }

    private function cancel(Donation $donation): Response
    {
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
