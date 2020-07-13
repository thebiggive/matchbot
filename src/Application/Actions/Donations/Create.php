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
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\SerializerInterface;

class Create extends Action
{
    private DonationRepository $donationRepository;
    private EntityManagerInterface $entityManager;
    private SerializerInterface $serializer;
    private StripeClient $stripeClient;

    public function __construct(
        DonationRepository $donationRepository,
        EntityManagerInterface $entityManager,
        LoggerInterface $logger,
        SerializerInterface $serializer,
        StripeClient $stripeClient
    ) {
        $this->donationRepository = $donationRepository;
        $this->entityManager = $entityManager;
        $this->serializer = $serializer;
        $this->stripeClient = $stripeClient;

        parent::__construct($logger);
    }

    /**
     * @return Response
     * @throws \MatchBot\Application\Matching\TerminalLockException if the matching adapter can't allocate funds
     */
    protected function action(): Response
    {
        try {
            /** @var DonationCreate $donationData */
            $donationData = $this->serializer->deserialize(
                $this->request->getBody(),
                DonationCreate::class,
                'json'
            );
        } catch (UnexpectedValueException $exception) { // This is the Serializer one, not the global one
            $message = 'Donation Create data deserialise error';
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
            $this->logger->info("Donation Create model load failure payload was: {$this->request->getBody()}");
            $error = new ActionError(ActionError::BAD_REQUEST, $exception->getMessage());

            return $this->respond(new ActionPayload(400, null, $error));
        }

        if (!$donation->getCampaign()->isOpen()) {
            $message = "Campaign {$donation->getCampaign()->getSalesforceId()} is not open";
            $this->logger->warning($message);
            $error = new ActionError(ActionError::BAD_REQUEST, $message);

            return $this->respond(new ActionPayload(400, null, $error));
        }

        if ($donation->getPsp() === 'stripe') {
            try {
                $intent = $this->stripeClient->paymentIntents->create([
                    // Stripe Payment Intent `amount` is in the smallest currency unit, e.g. pence.
                    // See https://stripe.com/docs/api/payment_intents/object
                    'amount' => (100 * $donation->getAmount()),
                    'currency' => 'gbp',
                ]);
            } catch (ApiErrorException $exception) {
                $this->logger->error(
                    'Stripe Payment Intent create error: ' .
                    get_class($exception) . ': ' . $exception->getMessage()
                );
                $error = new ActionError(ActionError::SERVER_ERROR, 'Could not make Stripe Payment Intent');
                return $this->respond(new ActionPayload(500, null, $error));
            }

            $donation->setClientSecret($intent->client_secret);
        }

        $this->entityManager->persist($donation);
        $this->entityManager->flush();

        if ($donation->getCampaign()->isMatched()) {
            try {
                $this->donationRepository->allocateMatchFunds($donation);
            } catch (DomainLockContentionException $exception) {
                $error = new ActionError(ActionError::SERVER_ERROR, 'Fund resource locked');

                return $this->respond(new ActionPayload(503, null, $error));
            }
        }

        $response = new DonationCreatedResponse();
        $response->donation = $donation->toApiModel();
        $response->jwt = Token::create($donation->getUuid());

        // Attempt immediate sync. Buffered for a future batch sync if the SF call fails.
        $this->donationRepository->push($donation, true);

        return $this->respondWithData($response);
    }
}
