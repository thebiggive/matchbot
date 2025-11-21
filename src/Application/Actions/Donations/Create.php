<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Donations;

use Doctrine\DBAL\Exception\ServerException as DBALServerException;
use Doctrine\ORM\Exception\ORMException;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Actions\ActionError;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\AssertionFailedException;
use MatchBot\Application\Auth\DonationToken;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\HttpModels\DonationCreatedResponse;
use MatchBot\Application\Matching\DbErrorPreventedMatch;
use MatchBot\Application\Settings;
use MatchBot\Client\Stripe;
use MatchBot\Domain\DomainException\CampaignNotOpen;
use MatchBot\Domain\DomainException\CharityAccountLacksNeededCapaiblities;
use MatchBot\Domain\DomainException\CouldNotMakeStripePaymentIntent;
use MatchBot\Domain\DomainException\DonationCreateModelLoadFailure;
use MatchBot\Domain\DomainException\StripeAccountIdNotSetForAccount;
use MatchBot\Domain\DomainException\WrongCampaignType;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\PersonId;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\RateLimiter\Exception\RateLimitExceededException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\SerializerInterface;

class Create extends Action
{
    private bool $enableNoReservationsMode;

    public function __construct(
        private DonationService $donationService,
        private SerializerInterface $serializer,
        LoggerInterface $logger,
        private Stripe $stripe,
        Settings $settings,
    ) {
        parent::__construct($logger);
        $this->enableNoReservationsMode = $settings->enableNoReservationsMode;
    }

    /**
     * @return Response
     * @throws \MatchBot\Application\Matching\TerminalLockException if the matching adapter can't allocate funds
     * @see PersonManagementAuthMiddleware
     */
    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        // The route at `/people/{personId}/donations` validates that the donor has permission to act
        // as the person, and sets this attribute to the Stripe Customer ID based on JWS claims, all
        // in `PersonManagementAuthMiddleware`. If the legacy route was used or if no such ID was in the
        // JWS, this is null.
        $pspCustomerId = $request->getAttribute(PersonManagementAuthMiddleware::PSP_ATTRIBUTE_NAME);
        \assert(is_string($pspCustomerId) || is_null($pspCustomerId));
        if (! (is_string($pspCustomerId) && trim($pspCustomerId) !== '')) {
            return $this->validationError(
                $response,
                "Customer ID required to create donation",
            );
        }

        $body = (string) $request->getBody();

        try {
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
            $donorId = $request->getAttribute(PersonManagementAuthMiddleware::PERSON_ID_ATTRIBUTE_NAME);
            \assert($donorId instanceof PersonId);

            $donation = $this->donationService->createDonation(
                $donationData,
                $pspCustomerId,
                $donorId
            );
        } catch (RateLimitExceededException $e) {
            $retryDelaySeconds = ($e->getRetryAfter()->getTimestamp() - time());
            return $this->respond(
                $response,
                new ActionPayload(
                    400,
                    ['retry_in' => $retryDelaySeconds],
                    new ActionError(
                        'DONATION_RATE_LIMIT_EXCEEDED',
                        'Donation rate limit reached, please try later'
                    )
                )
            );
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
        } catch (WrongCampaignType $e) {
            return $this->validationError(
                $response,
                $e->getMessage(),
                null,
                false,
            );
        } catch (CharityAccountLacksNeededCapaiblities) {
            return $this->respond(
                $response,
                new ActionPayload(
                // HTTP 409 Conflict can cover when requests conflicts w/ server configuration.
                    409,
                    null,
                    new ActionError(
                        ActionError::VERIFICATION_ERROR,
                        'Could not make Stripe Payment Intent (C)'
                    ),
                ),
            );
        } catch (CouldNotMakeStripePaymentIntent) {
            return $this->respond(
                $response,
                new ActionPayload(
                    500,
                    null,
                    new ActionError(
                        ActionError::SERVER_ERROR,
                        'Could not make Stripe Payment Intent (B)'
                    ),
                ),
            );
        } catch (ORMException | DBALServerException | DbErrorPreventedMatch $ex) {
            // '(D)' errors are DB persistence issues, typically ones that still exist after some retries.
            return $this->respond(
                $response,
                new ActionPayload(
                    500,
                    null,
                    new ActionError(
                        ActionError::SERVER_ERROR,
                        'Could not make Stripe Payment Intent (D)'
                    ),
                ),
            );
        }

        $stripeCustomerId = $donation->getPspCustomerId();
        \assert($stripeCustomerId !== null);
        $customerSession = $this->stripe->createCustomerSession($stripeCustomerId);

        $data = new DonationCreatedResponse(
            donation: $donation->toFrontEndApiModel($this->enableNoReservationsMode),
            jwt: DonationToken::create($donation->getUuid()->toString()),
            stripeSessionSecret: $customerSession->client_secret,
        );

        $this->logger->info('Stripe customer session expiry: ' . $customerSession->expires_at);

        return $this->respondWithData($response, $data, 201);
    }
}
