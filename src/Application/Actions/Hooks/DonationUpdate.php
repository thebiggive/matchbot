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

class DonationUpdate extends Action
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
     * @throws DomainRecordNotFoundException
     * @throws HttpBadRequestException
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

        $missingRequiredField = (
            empty($donationData->status) ||
            empty($donationData->billingPostalAddress) ||
            empty($donationData->countryCode) ||
            empty($donationData->emailAddress) ||
            empty($donationData->firstName) ||
            empty($donationData->lastName) ||
            !isset($donationData->giftAid, $donationData->optInTbgEmail) ||
            empty($donationData->transactionId)
        );
        if ($missingRequiredField) {
            $message = 'Hook missing required values';
            $this->logger->warning("Donation ID {$this->args['donationId']}: {$message}");
            $error = new ActionError(ActionError::BAD_REQUEST, $message);

            return $this->respond(new ActionPayload(400, null, $error));
        }

        $donation->setDonationStatus($donationData->status);
        $donation->setDonorPostalAddress($donationData->billingPostalAddress);
        $donation->setDonorCountryCode($donationData->countryCode);
        $donation->setDonorEmailAddress($donationData->emailAddress);
        $donation->setDonorFirstName($donationData->firstName);
        $donation->setDonorLastName($donationData->lastName);
        $donation->setGiftAid($donationData->giftAid);
        $donation->setTbgComms($donationData->optInTbgEmail);
        $donation->setTransactionId($donationData->transactionId);

        $this->logger->info("{$donation->getUuid()} tip amount set from: {$donationData->tipAmount}");
        $donation->setTipAmount((string) ($donationData->tipAmount ?? 0.0));

        $this->entityManager->persist($donation);
        $this->entityManager->flush();

        // We log if this fails but don't worry the webhook-sending payment client
        // about it. We'll re-try sending the updated status to Salesforce in a future
        // batch sync.
        $this->donationRepository->push($donation, false); // Attempt immediate sync to Salesforce

        return $this->respondWithData($donation->toApiModel(false));
    }
}
