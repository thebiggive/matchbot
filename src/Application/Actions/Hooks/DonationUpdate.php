<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Hooks;

use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\Pure;
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

/**
 * Enthuse donation information update hook.
 */
class DonationUpdate extends Action
{
    #[Pure]
    public function __construct(
        private DonationRepository $donationRepository,
        private EntityManagerInterface $entityManager,
        private SerializerInterface $serializer,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * @return Response
     * @throws DomainRecordNotFoundException
     * @throws HttpBadRequestException
     */
    protected function action(): Response
    {
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
            // TODO stop logging full hook data once we understand the cases where this is happening.
            $this->logger->info('Payload: ' . json_encode($donationData));
            $error = new ActionError(ActionError::BAD_REQUEST, $message);

            return $this->respond(new ActionPayload(400, null, $error));
        }

        $donation->setDonationStatus($donationData->status);
        $donation->setDonorBillingAddress($donationData->billingPostalAddress);
        $donation->setDonorCountryCode($donationData->countryCode);
        $donation->setDonorEmailAddress($donationData->emailAddress);
        $donation->setDonorFirstName($donationData->firstName);
        $donation->setDonorLastName($donationData->lastName);
        $donation->setGiftAid($donationData->giftAid);
        $donation->setTbgComms($donationData->optInTbgEmail);
        $donation->setTransactionId($donationData->transactionId);
        $donation->setCharityFee('enthuse'); // Charity fee can vary if gift aid is claimed.

        if (isset($donationData->tipAmount)) {
            $donation->setTipAmount((string) $donationData->tipAmount);
        }

        $this->entityManager->persist($donation);
        $this->entityManager->flush();

        // Enthuse are now sending hooks with a few statuses that represent something 'refund-like'. All known
        // statuses that should act like this appear in `Donation::$reversedStatuses`.
        if ($donation->isReversed() && $donation->getCampaign()->isMatched()) {
            $this->donationRepository->releaseMatchFunds($donation);
        }

        // We log if this fails but don't worry the webhook-sending payment client
        // about it. We'll re-try sending the updated status to Salesforce in a future
        // batch sync.
        $this->donationRepository->push($donation, false); // Attempt immediate sync to Salesforce

        return $this->respondWithData($donation->toApiModel());
    }
}
