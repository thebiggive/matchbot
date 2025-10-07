<?php

namespace MatchBot\Application\Actions\RegularGivingMandate;

use Doctrine\ORM\EntityManagerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Actions\ActionError;
use MatchBot\Application\AssertionFailedException;
use MatchBot\Application\Environment;
use MatchBot\Application\HttpModels\MandateCreate;
use MatchBot\Application\Security\Security;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DomainException\AccountDetailsMismatch;
use MatchBot\Domain\DomainException\CampaignNotOpen;
use MatchBot\Domain\DomainException\CouldNotCancelStripePaymentIntent;
use MatchBot\Domain\DomainException\CouldNotRetrievePaymentMethod;
use MatchBot\Domain\DomainException\DonationNotCollected;
use MatchBot\Domain\DomainException\MandateAlreadyExists;
use MatchBot\Domain\DomainException\NotFullyMatched;
use MatchBot\Domain\DomainException\PaymentIntentNotSucceeded;
use MatchBot\Domain\DomainException\WrongCampaignType;
use MatchBot\Domain\RegularGivingService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use Stripe\Exception\CardException;
use Stripe\PaymentIntent;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\SerializerInterface;

class Create extends Action
{
    public function __construct(
        private RegularGivingService $mandateService,
        private Environment $environment,
        LoggerInterface $logger,
        private CampaignRepository $campaignRepository,
        private SerializerInterface $serializer,
        private EntityManagerInterface $em,
        private \DateTimeImmutable $now,
        private Security $securityService,
    ) {
        parent::__construct($logger);
    }

    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        if (! $this->environment->isFeatureEnabledRegularGiving()) {
            throw new HttpNotFoundException($request);
        }

        $donor = $this->securityService->requireAuthenticatedDonorAccountWithPassword($request);
        $body = (string) $request->getBody();

        try {
            $mandateData = $this->serializer->deserialize($body, MandateCreate::class, 'json');
        } catch (AssertionFailedException $e) {
            // This will return a message such as below if the donor gives their home address as just "X":
            //  "Value "X" is too short, it should have at least 2 characters, but only has 1 characters."
            // Not perfect but more helpful than nothing. FE validation should be added so users won't need to see
            // this message.
            return $this->validationError(
                response: $response,
                logMessage: $e->getMessage(),
            );
        } catch (\TypeError | UnexpectedValueException $exception) {
            /** similar catch with commentary in @see \MatchBot\Application\Actions\Donations\Create */
            $this->logger->info("Mandate Create non-serialisable payload was: $body");

            $message = 'Mandate create data deserialise error';
            $exceptionType = get_class($exception);

            return $this->validationError(
                response: $response,
                logMessage: "$message: $exceptionType - {$exception->getMessage()}",
                publicMessage: $message,
            );
        }

        $campaign = $this->campaignRepository->findOneBySalesforceId($mandateData->campaignId);
        if (!$campaign) {
            // For a donation we would pull the campaign and charity from Salesforce at this point, but that adds
            // some complexity. For a regular giving mandate I'm hoping we can arrange for the campaigns and charities
            // to be in the Matchbot database in advance of any donor filling in the mandate form, e.g. using an SF
            // trigger or matchbot polling

            throw new HttpBadRequestException($request, 'Campaign not found');
        }

        $charity = $campaign->getCharity();

        try {
            $mandate = $this->mandateService->setupNewMandate(
                donor: $donor,
                amount: $mandateData->amount,
                campaign: $campaign,
                giftAid: $mandateData->giftAid,
                billingCountry: $mandateData->billingCountry,
                billingPostCode: $mandateData->billingPostcode,
                tbgComms: $mandateData->tbgComms,
                charityComms: $mandateData->charityComms,
                confirmationTokenId: $mandateData->stripeConfirmationTokenId,
                homeAddress: $mandateData->homeAddress,
                homePostcode: $mandateData->homePostcode,
                matchDonations: !$mandateData->unmatched,
                homeIsOutsideUk: $mandateData->homeIsOutsideUK,
            );
        } catch (WrongCampaignType | \UnexpectedValueException $e) {
            return $this->validationError(
                $response,
                $e->getMessage(),
                null,
                false,
            );
        } catch (NotFullyMatched $e) {
            $maxMatchable = $e->maxMatchable;
            return $this->validationError(
                $response,
                logMessage: $e->getMessage(),
                publicMessage: $maxMatchable->isZero() ?
                        // Strictly speaking there may be *some* match funds available, but less than £3.00 so it's not
                        // possible to make three matched donations and these funds are effectively unusable for now.
                    "Sorry, we could not take your regular donation as there are no match funds available." :
                    "Sorry, we could not take your regular donation as there are not enough match funds available.",
                reduceSeverity: false,
                errorType: ActionError::INSUFFICIENT_MATCH_FUNDS,
                errorData: ['maxMatchable' => $maxMatchable],
            );
        } catch (CampaignNotOpen $e) {
            return $this->validationError(
                $response,
                logMessage: $e->getMessage(),
                publicMessage: "Sorry, the {$campaign->getCampaignName()} campaign is not open at this time.",
                reduceSeverity: false,
            );
        } catch (DonationNotCollected $e) {
            return $this->validationError(
                $response,
                logMessage: $e->getMessage(),
                publicMessage: 'Sorry, we were not able to collect the payment for your first donation. ' .
                'No regular giving agreement has been created.' .
                'Consider using another payment method or contacting your card issuer.',
                reduceSeverity: false,
            );
        } catch (MandateAlreadyExists | CouldNotCancelStripePaymentIntent $exception) {
            // CouldNotCancelStripePaymentIntent will be thrown if the other mandate is not yet activated but has
            // a collected first donation
            return new JsonResponse([
                'error' => [
                    'message' => $exception->getMessage(),
                    'publicMessage' => $exception->getMessage(),
                ],
            ], 400);
        } catch (CardException $exception) {
            return new JsonResponse([
                'error' => [
                    'message' => $exception->getMessage(),
                    'publicMessage' => $exception->getMessage(),
                    'code' => $exception->getStripeCode(),
                    'decline_code' => $exception->getDeclineCode(),
                ],
            ], 402);
        } catch (PaymentIntentNotSucceeded $exception) {
            $intent = $exception->paymentIntent;

            // Regular Giving service only throws this if the PI requires action, e.g. 3DS authentication.
            \assert($intent->status === PaymentIntent::STATUS_REQUIRES_ACTION);

            return new JsonResponse([
                'mandate' => $exception->mandate?->toFrontEndApiModel($charity, $this->now),
                'paymentIntent' => [
                    'status' => $intent->status,
                    'client_secret' =>  $intent->client_secret
                ],
            ]);
        } catch (AccountDetailsMismatch $e) {
            $this->logger->warning("AccountDetailsMismatch: {$e->getMessage()}");
            return $this->validationError(
                $response,
                logMessage: $e->getMessage(),
                publicMessage: "Your account information may have changed after you loaded this page. Please refresh and try again.",
            );
        } catch (CouldNotRetrievePaymentMethod $e) {
            $this->logger->warning("CouldNotRetrievePaymentMethod: {$e->getMessage()}");
            return $this->validationError(
                $response,
                logMessage: $e->getMessage(),
                publicMessage: "Your saved payment method could not be retrieved. Please refresh and try using a different payment method.",
            );
        }

        // create first three pending donations for mandate.

        // throw if any donation is not fully matched, (unless the donor has told us that they're OK with making an
        // unmatched or partially matched donation)

        // tell stripe to take payment for first donation. Throw if payment fails synchronously.

        // another class will receive the event from stripe later to say first donation is collected, and
        // then activate the mandate (i.e. update it status using RegularGivingMandate::activate ) and email
        // the donor.

        // Return some details of the pending mandate to FE. FE will poll the mandate for the update to show
        // when the mandate is active.

        $this->em->flush();
        return new JsonResponse(['mandate' => $mandate->toFrontEndApiModel($charity, $this->now)], 201);
    }
}
