<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\Pure;
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
    #[Pure]
    public function __construct(
        private DonationRepository $donationRepository,
        private EntityManagerInterface $entityManager,
        private SerializerInterface $serializer,
        private StripeClient $stripeClient,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * @return Response
     * @throws \MatchBot\Application\Matching\TerminalLockException if the matching adapter can't allocate funds
     */
    protected function action(): Response
    {
        $body = (string) $this->request->getBody();

        try {
            /** @var DonationCreate $donationData */
            $donationData = $this->serializer->deserialize($body, DonationCreate::class, 'json');
        } catch (UnexpectedValueException $exception) { // This is the Serializer one, not the global one
            $this->logger->info("Donation Create non-serialisable payload was: $body");

            $message = 'Donation Create data deserialise error';
            $exceptionType = get_class($exception);

            return $this->validationError(
                "$message: $exceptionType - {$exception->getMessage()}",
                $message,
                empty($body), // Suspected bot / junk traffic sometimes sends blank payload.
            );
        }

        try {
            $donation = $this->donationRepository->buildFromApiRequest($donationData);
        } catch (\UnexpectedValueException $exception) {
            $this->logger->info("Donation Create model load failure payload was: $body");

            $message = 'Donation Create data initial model load';
            $this->logger->warning($message . ': ' . $exception->getMessage());

            return $this->validationError($message . ': ' . $exception->getMessage(), $exception->getMessage());
        } catch (UniqueConstraintViolationException $exception) {
            // If we get this, the most likely explanation is that another donation request
            // created the same campaign a very short time before this request tried to. We
            // saw this 3 times in the opening minutes of CC20 on 1 Dec 2020.
            // If this happens, the latest campaign data should already have been pulled and
            // persisted in the last second. So give the same call one more try, as
            // buildFromApiRequest() should perform a fresh call to `CampaignRepository::findOneBy()`.
            $this->logger->info(sprintf(
                'Got campaign pull UniqueConstraintViolationException for campaign ID %s. Trying once more.',
                $donationData->projectId,
            ));
            $donation = $this->donationRepository->buildFromApiRequest($donationData);
        }

        if (!$donation->getCampaign()->isOpen()) {
            return $this->validationError(
                "Campaign {$donation->getCampaign()->getSalesforceId()} is not open",
                null,
                true, // Reduce to info log as some instances expected on campaign close
            );
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
                // Let's create a Stripe Customer, so we can link the PaymentIntent to it later
                $customer = $this->stripeClient->customers->create([
                    'address' => [
                        'line1' => $donation->getDonorHomeAddressLine1(),
                        'postal_code' => $donation->getDonorHomePostcode(), // or should it be $donorPostalAddress?
                        'country' => $donation->getDonorCountryCode(),
                    ],
                    'description' => null,
                    'email' => $donation->getDonorEmailAddress(),
                    'metadata' => [
                        //'donorUuid' => $donation->getDonorUuid(), Do we need something like this to link the Stripe
                        //                                          Customer to the related uuid from the Id Service?
                    ],
                    'name' => $donation->getDonorFirstName() . ' ' . $donation->getDonorLastName(),
                    'phone' => null,
                    // 'shipping' => [
                    //    'address' => [...] Should we add a shipping address?
                    // ],
                ]);
            } catch (ApiErrorException $exception) {
                return $this->logAndRespondWithError(sprintf(
                    'Stripe Customer create error on %s, %s [%s]: %s. Charity: %s [%s].',
                    $donation->getUuid(),
                    $exception->getStripeCode(),
                    get_class($exception),
                    $exception->getMessage(),
                    $donation->getCampaign()->getCharity()->getName(),
                    $donation->getCampaign()->getCharity()->getStripeAccountId()
                ), 'Could not make Stripe Customer (B)');
            }

            try {
                $intent = $this->stripeClient->paymentIntents->create([
                    // Stripe Payment Intent `amount` is in the smallest currency unit, e.g. pence.
                    // See https://stripe.com/docs/api/payment_intents/object
                    'amount' => $donation->getAmountFractionalIncTip(),
                    'currency' => strtolower($donation->getCurrencyCode()),
                    'customer' => $customer->id,
                    'description' => $donation->__toString(),
                    'metadata' => [
                        /**
                         * Keys like comms opt ins are set only later. See the counterpart
                         * in {@see Update::addData()} too.
                         */
                        'campaignId' => $donation->getCampaign()->getSalesforceId(),
                        'campaignName' => $donation->getCampaign()->getCampaignName(),
                        'charityId' => $donation->getCampaign()->getCharity()->getDonateLinkId(),
                        'charityName' => $donation->getCampaign()->getCharity()->getName(),
                        'donationId' => $donation->getUuid(),
                        'environment' => getenv('APP_ENV'),
                        'feeCoverAmount' => $donation->getFeeCoverAmount(),
                        'matchedAmount' => $donation->getFundingWithdrawalTotal(),
                        'stripeFeeRechargeGross' => $donation->getCharityFeeGross(),
                        'stripeFeeRechargeNet' => $donation->getCharityFee(),
                        'stripeFeeRechargeVat' => $donation->getCharityFeeVat(),
                        'tipAmount' => $donation->getTipAmount(),
                    ],
                    'statement_descriptor' => $this->getStatementDescriptor($donation->getCampaign()->getCharity()),
                    // See https://stripe.com/docs/connect/destination-charges#application-fee
                    'application_fee_amount' => $donation->getAmountToDeductFractional(),
                    // See https://stripe.com/docs/payments/connected-accounts and
                    // https://stripe.com/docs/connect/destination-charges#settlement-merchant
                    'on_behalf_of' => $donation->getCampaign()->getCharity()->getStripeAccountId(),
                    'transfer_data' => [
                        'destination' => $donation->getCampaign()->getCharity()->getStripeAccountId(),
                    ],
                ]);
            } catch (ApiErrorException $exception) {
                return $this->logAndRespondWithError(sprintf(
                    'Stripe Payment Intent create error on %s, %s [%s]: %s. Charity: %s [%s].',
                    $donation->getUuid(),
                    $exception->getStripeCode(),
                    get_class($exception),
                    $exception->getMessage(),
                    $donation->getCampaign()->getCharity()->getName(),
                    $donation->getCampaign()->getCharity()->getStripeAccountId()
                ), 'Could not make Stripe Payment Intent (B)');
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

        return $this->respondWithData($response, 201);
    }

    private function getStatementDescriptor(Charity $charity): string
    {
        $maximumLength = 22; // https://stripe.com/docs/payments/payment-intents#dynamic-statement-descriptor
        $prefix = 'The Big Give ';

        return $prefix . mb_substr(
            $this->removeSpecialChars($charity->getName()),
            0,
            $maximumLength - mb_strlen($prefix),
        );
    }

    // Remove special characters except spaces
    private function removeSpecialChars(string $descriptor): string
    {
        return preg_replace('/[^A-Za-z0-9 ]/', '', $descriptor);
    }

    private function logAndRespondWithError(
        string $logMessage,
        string $errorDescription,
    ): Response {
        $this->logger->error($logMessage);
        $error = new ActionError(ActionError::SERVER_ERROR, $errorDescription);
        return $this->respond(new ActionPayload(500, null, $error));
    }
}
