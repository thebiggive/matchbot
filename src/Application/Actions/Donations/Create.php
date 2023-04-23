<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Actions\ActionError;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\Auth\DonationToken;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\HttpModels\DonationCreatedResponse;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\Charity;
use MatchBot\Domain\DomainException\DomainLockContentionException;
use MatchBot\Domain\DonationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
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
     * @see PersonManagementAuthMiddleware
     */
    protected function action(Request $request, Response $response, array $args): Response
    {
        // The route at `/people/{personId}/donations` validates that the donor has permission to act
        // as the person, and sets this attribute to the Stripe Customer ID based on JWS claims, all
        // in `PersonManagementAuthMiddleware`. If the legacy route was used or if no such ID was in the
        // JWS, this is null.
        $customerId = $request->getAttribute('pspId');

        $body = (string) $request->getBody();

        try {
            /** @var DonationCreate $donationData */
            $donationData = $this->serializer->deserialize($body, DonationCreate::class, 'json');
        } catch (UnexpectedValueException $exception) { // This is the Serializer one, not the global one
            $this->logger->info("Donation Create non-serialisable payload was: $body");

            $message = 'Donation Create data deserialise error';
            $exceptionType = get_class($exception);

            return $this->validationError($response,
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

            return $this->validationError($response, $message . ': ' . $exception->getMessage(), $exception->getMessage());
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

        if ($customerId !== $donation->getPspCustomerId()) {
            return $this->validationError($response, sprintf(
                'Route customer ID %s did not match %s in donation body',
                $customerId,
                $donation->getPspCustomerId(),
            ));
        }

        if (!$donation->getCampaign()->isOpen()) {
            return $this->validationError($response,
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

                return $this->respond($response, new ActionPayload(503, null, $error));
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
                    return $this->respond($response, new ActionPayload(500, null, $error));
                }

                // Else we found new Stripe info and can proceed
                $donation->setCampaign($campaign);
            }

            $createPayload = [
                ...$donation->getStripeMethodProperties(),
                ...$donation->getStripeOnBehalfOfProperties(),
                // Stripe Payment Intent `amount` is in the smallest currency unit, e.g. pence.
                // See https://stripe.com/docs/api/payment_intents/object
                'amount' => $donation->getAmountFractionalIncTip(),
                'currency' => strtolower($donation->getCurrencyCode()),
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
                'transfer_data' => [
                    'destination' => $donation->getCampaign()->getCharity()->getStripeAccountId(),
                ],
            ];

            // For now 'customer' may be omitted – and an automatic, guest customer used by Stripe –
            // depending on the frontend mode. If there *is* a customer, we want to be able to offer them
            // card reuse.
            if ($customerId !== null) {
                $createPayload['customer'] = $customerId;

                if ($donation->supportsSavingPaymentMethod()) {
                    $createPayload['setup_future_usage'] = 'on_session';
                }
            }

            try {
                $intent = $this->stripeClient->paymentIntents->create($createPayload);
            } catch (ApiErrorException $exception) {
                if ($donation->getCampaign()->getId() === 4690) {
                    $this->logger->warning(sprintf(
                        'Stripe Payment Intent create error on %s, %s [%s]: %s. Known charity: %s [%s].',
                        $donation->getUuid(),
                        $exception->getStripeCode(),
                        get_class($exception),
                        $exception->getMessage(),
                        $donation->getCampaign()->getCharity()->getName(),
                        $donation->getCampaign()->getCharity()->getStripeAccountId()
                    ));
                } else {
                    $this->logger->error(sprintf(
                        'Stripe Payment Intent create error on %s, %s [%s]: %s. Charity: %s [%s].',
                        $donation->getUuid(),
                        $exception->getStripeCode(),
                        get_class($exception),
                        $exception->getMessage(),
                        $donation->getCampaign()->getCharity()->getName(),
                        $donation->getCampaign()->getCharity()->getStripeAccountId()
                    ));
                }
                $error = new ActionError(ActionError::SERVER_ERROR, 'Could not make Stripe Payment Intent (B)');
                return $this->respond($response, new ActionPayload(500, null, $error));
            }

            $donation->setClientSecret($intent->client_secret);
            $donation->setTransactionId($intent->id);

            $this->entityManager->persist($donation);
            $this->entityManager->flush();
        }

        $data = new DonationCreatedResponse();
        $data->donation = $donation->toApiModel();
        $data->jwt = DonationToken::create($donation->getUuid());

        // Attempt immediate sync. Buffered for a future batch sync if the SF call fails.
        $this->donationRepository->push($donation, true);

        return $this->respondWithData($response, $data, 201);
    }

    private function getStatementDescriptor(Charity $charity): string
    {
        $maximumLength = 22; // https://stripe.com/docs/payments/payment-intents#dynamic-statement-descriptor
        $prefix = 'Big Give ';

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
}
