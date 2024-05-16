<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use MatchBot\Application\Actions\Action;
use MatchBot\Application\Actions\ActionError;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\AssertionFailedException;
use MatchBot\Application\Auth\DonationToken;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\HttpModels\DonationCreatedResponse;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Application\Notifier\StripeChatterInterface;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
use MatchBot\Client\Stripe;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Charity;
use MatchBot\Domain\DomainException\CampaignNotOpen;
use MatchBot\Domain\DomainException\CouldNotMakeStripePaymentIntent;
use MatchBot\Domain\DomainException\DonationCreateModelLoadFailure;
use MatchBot\Domain\DomainException\StripeAccountIdNotSetForAccount;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\SerializerInterface;

class Create extends Action
{

    public function __construct(
        private DonationRepository $donationRepository,
        private DonationService $donationService,
        private SerializerInterface $serializer,
        LoggerInterface $logger,
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
        $customerId = $request->getAttribute(PersonWithPasswordAuthMiddleware::PSP_ATTRIBUTE_NAME);
        \assert(is_string($customerId) || is_null($customerId));

        $body = (string) $request->getBody();

        try {
            /** @var DonationCreate $donationData */
            $donationData = $this->serializer->deserialize($body, DonationCreate::class, 'json');
        } catch (\TypeError | UnexpectedValueException | AssertionFailedException $exception) {
            // UnexpectedValueException is the Serializer one,
            // not the global one

            // Ideally rather than catching type error we would configure the seralizer use
            // the Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor and then it would throw a
            // NotNormalizableValueException instead of calling the constructor and having that throw a TypeError.
            //
            // But that requires more changes than I want to make right now to either Donation http model used for
            // updates, as well as the DonationCreate model used here or the tests that sometimes pass strings where
            // numbers are specified or vice versa.

            $this->logger->info("Donation Create non-serialisable payload was: $body");

            $message = 'Donation Create data deserialise error';
            $exceptionType = get_class($exception);

            return $this->validationError(
                $response,
                "$message: $exceptionType - {$exception->getMessage()}",
                $message,
                empty($body), // Suspected bot / junk traffic sometimes sends blank payload.
            );
        }

        try {
            $donation = $this->donationService->createDonation($donationData, $customerId);
        } catch (StripeAccountIdNotSetForAccount) {
            return $this->respond(
                $response,
                new ActionPayload(
                    500,
                    null,
                    new ActionError(ActionError::SERVER_ERROR, 'Could not make Stripe Payment Intent (A)')
                )
            );
        } catch (\UnexpectedValueException $e) {
            return $this->validationError(
                $response,
                $e->getMessage()
            );
        } catch (DonationCreateModelLoadFailure $e) {
            $originalMessage = $e->getPrevious()?->getMessage();
            return $this->validationError(
                $response,
                $e->getMessage() . ': ' . $e->getMessage(),
                $originalMessage,
            );
        } catch (CampaignNotOpen $e) {
            return $this->validationError(
                $response,
                $e->getMessage(),
                null,
                true, // Reduce to info log as some instances expected on campaign close
            );
        } catch (CouldNotMakeStripePaymentIntent $e) {
            return $this->respond(
                $response,
                new ActionPayload(
                // HTTP 409 Conflict can cover when requests conflicts w/ server configuration.
                    $e->accountLacksCapabilities ? 409 : 500,
                    null,
                    new ActionError(
                        $e->accountLacksCapabilities ? ActionError::VERIFICATION_ERROR : ActionError::SERVER_ERROR,
                        'Could not make Stripe Payment Intent (B)'
                    ),
                ),
            );
        }

        $data = new DonationCreatedResponse();
        $data->donation = $donation->toApiModel();
        $data->jwt = DonationToken::create($donation->getUuid());

        // Attempt immediate sync. Buffered for a future batch sync if the SF call fails.
        $this->donationRepository->push($donation, true);

        return $this->respondWithData($response, $data, 201);
    }
}
