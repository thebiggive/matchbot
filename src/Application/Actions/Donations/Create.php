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
use MatchBot\Domain\Campaign;
use MatchBot\Domain\Charity;
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
            $this->logger->info("Donation Create non-serialisable payload was: {$this->request->getBody()}");

            $message = 'Donation Create data deserialise error';
            $exceptionType = get_class($exception);

            return $this->validationError("$message: $exceptionType - {$exception->getMessage()}", $message);
        }

        try {
            $donation = $this->donationRepository->buildFromApiRequest($donationData);
        } catch (\UnexpectedValueException $exception) {
            $this->logger->info("Donation Create model load failure payload was: {$this->request->getBody()}");

            $message = 'Donation Create data initial model load';
            $this->logger->warning($message . ': ' . $exception->getMessage());

            return $this->validationError($message . ': ' . $exception->getMessage(), $exception->getMessage());
        }

        if (!$donation->getCampaign()->isOpen()) {
            return $this->validationError("Campaign {$donation->getCampaign()->getSalesforceId()} is not open");
        }

        // Must persist before Stripe work to have ID available.
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

        if ($donation->getPsp() === 'stripe') {
            if (empty($donation->getCampaign()->getCharity()->getStripeAccountId())) {
                // Try re-pulling in case charity has very recently onboarded with for Stripe.
                $campaign = $this->entityManager->getRepository(Campaign::class)
                    ->pull($donation->getCampaign());

                // If still empty, error out
                if (empty($campaign->getCharity()->getStripeAccountId())) {
                    $this->logger->error(sprintf(
                        'Stripe Payment Intent create error: Stripe Account ID not set for Account %s',
                        $donation->getCampaign()->getCharity()->getSalesforceId(),
                    ));
                    $error = new ActionError(ActionError::SERVER_ERROR, 'Could not make Stripe Payment Intent (A)');
                    return $this->respond(new ActionPayload(500, null, $error));
                }

                // Else we found new Stripe info and can proceed
                $donation->setCampaign($campaign);
            }

            try {
                $intent = $this->stripeClient->paymentIntents->create([
                    // Stripe Payment Intent `amount` is in the smallest currency unit, e.g. pence.
                    // See https://stripe.com/docs/api/payment_intents/object
                    'amount' => (100 * $donation->getAmount()) + (100 * $donation->getTipAmount() ?? 0),
                    'currency' => 'gbp',
                    'description' => $donation->__toString(),
                    'metadata' => [
                        'campaignId' => $donation->getCampaign()->getSalesforceId(),
                        'campaignName' => $donation->getCampaign()->getCampaignName(),
                        'charityId' => $donation->getCampaign()->getCharity()->getDonateLinkId(),
                        'charityName' => $donation->getCampaign()->getCharity()->getName(),
                        'donationId' => $donation->getUuid(),
                        'environment' => getenv('APP_ENV'),
                        'matchedAmount' => $donation->getFundingWithdrawalTotal(),
                        'tipAmount' => $donation->getTipAmount(),
                    ],
                    'statement_descriptor' => $this->getStatementDescriptor($donation->getCampaign()->getCharity()),
                    // See https://stripe.com/docs/connect/destination-charges
                    'transfer_data' => [
                        'amount' => (100 * $donation->getAmountForCharity()),
                        'destination' => $donation->getCampaign()->getCharity()->getStripeAccountId(),
                    ],
                ]);
            } catch (ApiErrorException $exception) {
                $this->logger->error(
                    'Stripe Payment Intent create error: ' .
                    get_class($exception) . ': ' . $exception->getMessage()
                );
                $error = new ActionError(ActionError::SERVER_ERROR, 'Could not make Stripe Payment Intent (B)');
                return $this->respond(new ActionPayload(500, null, $error));
            }

            $donation->setClientSecret($intent->client_secret);
            $donation->setTransactionId($intent->id);

            $this->entityManager->persist($donation);
            $this->entityManager->flush();
        }

        $response = new DonationCreatedResponse();
        $response->donation = $donation->toApiModel();
        $response->jwt = Token::create($donation->getUuid());

        // Attempt immediate sync. Buffered for a future batch sync if the SF call fails.
        $this->donationRepository->push($donation, true);

        return $this->respondWithData($response);
    }

    private function getStatementDescriptor(Charity $charity): string
    {
        $maximumLength = 22; // https://stripe.com/docs/payments/payment-intents#dynamic-statement-descriptor
        $prefix = 'The Big Give ';

        return $prefix . substr($charity->getName(), 0, $maximumLength - strlen($prefix));
    }
}
