<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Hooks;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\Action;
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

        $donation->setDonationStatus($donationData->status);
        $donation->setDonorFirstName($donationData->firstName);
        $donation->setDonorLastName($donationData->firstName);
        $donation->setGiftAid($donationData->giftAid);
        $donation->setTbgComms($donationData->optInTbgEmail);
        $donation->setTransactionId($donationData->transactionId);

        $this->entityManager->persist($donation);

        // TODO check this handles errors without crashing. Consider only queueing so we're not waiting for SF.
        $this->donationRepository->push($donation); // Attempt immediate sync to Salesforce

        return $this->respondWithData($this->serializer->serialize($donation, 'json'));
    }
}
