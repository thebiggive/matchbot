<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Auth\Token;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\HttpModels\DonationCreatedResponse;
use MatchBot\Domain\DonationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
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
        $this->request->getBody();
        $this->logger->info('REQUEST: ' . $this->request->getBody());

        $donationData = $this->serializer->deserialize(
            $this->request->getBody(),
            DonationCreate::class,
            'json'
        );
        $donation = $this->donationRepository->buildFromApiRequest($donationData);

        if ($donation->getCampaign()->isMatched()) {
            $this->donationRepository->allocateMatchFunds($donation);
        }

        $this->entityManager->persist($donation);
        $this->entityManager->flush(); // We need the UUID generated to generate a JWT.

        $response = new DonationCreatedResponse();
        $response->donation = $donation;
        $response->jwt = Token::create($donation->getUuid());

        // TODO check this handles errors without crashing. Consider only queueing so we're not waiting for SF.
        $this->donationRepository->push($donation); // Attempt immediate sync to Salesforce

        return $this->respondWithData($this->serializer->serialize($response, 'json'));
    }
}
