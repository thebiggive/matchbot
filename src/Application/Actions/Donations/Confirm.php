<?php

namespace MatchBot\Application\Actions\Donations;

use Doctrine\ORM\EntityManagerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Assertion;
use MatchBot\Application\LazyAssertionException;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Application\Settings;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\DomainException\PaymentIntentNotSucceeded;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\DonationStatus;
use MatchBot\Domain\StripeConfirmationTokenId;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Ramsey\Uuid\Uuid;
use Slim\Exception\HttpBadRequestException;
use Stripe\ConfirmationToken;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\Exception\RateLimitExceededException;

class Confirm extends Action
{
    private bool $enableNoReservationsMode;

    public function __construct(
        LoggerInterface $logger,
        private DonationRepository $donationRepository,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private DonationService $donationService,
        private ClockInterface $clock,
        private LockFactory $lockFactory,
        Settings $settings,
    ) {
        parent::__construct($logger);
        $this->enableNoReservationsMode = $settings->enableNoReservationsMode;
    }

    public static function donationConfirmLockKey(Donation $donation): string
    {
        return 'donation-confirm-lock-' . $donation->getUuid()->toString();
    }

    /**
     * Called to confirm that the donor wishes to make a donation immediately. We will tell Stripe to take their money
     * using the payment method provided. Does not update the donation status, that only gets updated when stripe calls
     * back to say the money is taken.
     */
    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        try {
            $requestBody = json_decode(
                $request->getBody()->getContents(),
                true,
                512,
                \JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            throw new HttpBadRequestException($request, 'Cannot parse request body as JSON');
        }
        \assert(is_array($requestBody));

        $paymentMethodId = $requestBody['stripePaymentMethodId'] ?? null;
        \assert(is_string($paymentMethodId) || is_null($paymentMethodId));
        $confirmationTokenId = $requestBody['stripeConfirmationTokenId'] ?? null;
        \assert(is_string($confirmationTokenId) || is_null($confirmationTokenId));
        /** @var null|'on_session'|'off_session' $confirmationTokenFutureUsage */
        $confirmationTokenFutureUsage = $requestBody['stripeConfirmationTokenFutureUsage'] ?? null;
        Assertion::inArray($confirmationTokenFutureUsage, [
            ConfirmationToken::SETUP_FUTURE_USAGE_OFF_SESSION,
            ConfirmationToken::SETUP_FUTURE_USAGE_ON_SESSION,
            null,
        ]);

        $this->entityManager->beginTransaction();

        Assertion::string($args['donationId']);
        $donation = $this->donationRepository->findAndLockOneByUUID(Uuid::fromString($args['donationId']));
        if (! $donation) {
            throw new NotFoundException();
        }

        if (!is_string($confirmationTokenId) || trim($confirmationTokenId) === '') {
            $donationUUID = $donation->getId();
            $this->logger->warning(
                <<<EOF
Donation Confirmation attempted with missing confirmation token id "$confirmationTokenId" for Donation $donationUUID
EOF
            );
            throw new HttpBadRequestException($request, "stripeConfirmationTokenId required");
        }


        \assert($paymentMethodId !== ""); // required to call updatePaymentMethodBillingDetail

        if (!$donation->hasExpectedMatchingReserved()) {
            if ($this->enableNoReservationsMode) {
                $this->logger->notice(sprintf(
                    'Donation %s does not have expected match funds reserved at confirmation, but allowing confirmation anyway in no reservations mode',
                    $donation->getUuid(),
                ));
            } else {
                $this->entityManager->rollback();
                $this->logger->warning(sprintf(
                    'Donation %s does not have expected match funds reserved at confirmation',
                    $donation->getUuid(),
                ));
                return new JsonResponse([
                    'error' => [
                        'message' => 'Donation does not have expected match funds reserved',
                        'code' => 'donation-no-match-funds-reserved',
                    ],
                ], 400);
            }
        }

        // AssertIsReadyToConfirm is going to check that the donation has all the match funds expected. We aquire a lock to make sure those funds
        // can't be released after we've checked.
        $lock = $this->lockFactory->createLock($this->donationConfirmLockKey($donation), autoRelease: true);
        try {
            $lock->acquire(blocking: true);
        } catch (LockConflictedException | LockAcquiringException) {
            // may be impossible or only happen if the confirmation is at exactly the same time as the funds
            //auto-release, or there are simultaneous confirmation requests for the same donation.
            return new JsonResponse([
                'error' => [
                    'message' => 'Could not lock donation to check match funds still withdrawn',
                    'code' => 'donation-not-locked',
                ],
            ], 400);
        }

        try {
            $donation->assertIsReadyToConfirm($this->clock->now());
        } catch (LazyAssertionException $exception) {
            $message = $exception->getMessage();

            // `matchedAmount` is £0 if donation was cancelled, which can happen not uncommonly on Stripe auto-cancels.
            $level = \str_contains(haystack: $message, needle: 'matchedAmount') && $donation->getDonationStatus() !== DonationStatus::Cancelled
                ? LogLevel::ERROR
                : LogLevel::WARNING;

            $this->logger->log(level: $level, message: $message);

            throw new HttpBadRequestException($request, $message);
        }

        $paymentIntentId = $donation->getTransactionId();
        Assertion::notNull($paymentIntentId);

        try {
            $updatedIntent = $this->donationService->confirmOnSessionDonation(
                $donation,
                StripeConfirmationTokenId::of($confirmationTokenId),
                $confirmationTokenFutureUsage,
            );
        } catch (CardException $exception) {
            $this->entityManager->rollback();

            return $this->handleCardException(
                context: 'confirmPaymentIntent',
                exception: $exception,
                donation: $donation,
                paymentIntentId: $paymentIntentId,
            );
        } catch (InvalidRequestException $exception) {
            if (!DonationService::errorMessageFromStripeIsExpected($exception)) {
                throw new InvalidRequestException(
                    'Donation UUID: ' . $donation->getUuid()->toString() . ', ' . $exception->getMessage(),
                    $exception->getCode(),
                    $exception
                );
            }

            $exceptionClass = get_class($exception);
            $this->logger->warning(sprintf(
                'Stripe %s on Confirm for donation %s (%s): %s',
                $exceptionClass,
                $donation->getUuid(),
                $paymentIntentId,
                $exception->getMessage(),
            ));

            $this->entityManager->rollback();

            return new JsonResponse([
                'error' => [
                    'message' => $exception->getMessage(),
                    'code' => $exception->getStripeCode()
                ],
            ], 402);
        } catch (RateLimitExceededException $exception) {
            $this->logger->warning(sprintf(
                'Rate limit exceed on Confirm for donation %s (%s): %s',
                $donation->getUuid(),
                $paymentIntentId,
                $exception->getMessage(),
            ));

            return new JsonResponse([
                'error' => [
                    'message' => 'Rate limited - please try try later',
                    'code' => 429
                ],
            ], 402);
        } catch (ApiErrorException $exception) {
            $this->logger->error(sprintf(
                'Stripe %s on Confirm for donation %s (%s): %s',
                get_class($exception),
                $donation->getUuid(),
                $paymentIntentId,
                $exception->getMessage(),
            ));

            $this->entityManager->rollback();

            return new JsonResponse([
                'error' => [
                    'message' => $exception->getMessage(),
                    'code' => $exception->getStripeCode(),
                ],
            ], 500);
        } catch (PaymentIntentNotSucceeded $_e) {
            // no-op - in this case we return the unsuccessful PI to the FE just like we would a successful one.
            // FE handles it.
            $updatedIntent = $_e->paymentIntent;
        }

        // Assuming Stripe calls worked, commit any changes that `deriveFees()` made to the EM-tracked `$donation`.
        // In edge cases where setup_future_usage choice changed after an initial failed Confirm, there may also
        // be a new transactionId (Payment Intent ID) to save.
        $this->entityManager->flush();
        $this->entityManager->commit();

        $this->bus->dispatch(DonationUpserted::fromDonationEnveloped($donation));

        $donation->setSalesforceUpdatePending();

        return new JsonResponse([
            'paymentIntent' => [
                'status' => $updatedIntent->status,
                'client_secret' => $updatedIntent->status === 'requires_action'
                    ?  $updatedIntent->client_secret
                    : null,
            ],
        ]);
    }

    private function handleCardException(
        string $context,
        CardException $exception,
        Donation $donation,
        string $paymentIntentId,
    ): JsonResponse {
        $exceptionClass = get_class($exception);
        $this->logger->info(sprintf(
            'Stripe %s on Confirm %s for donation %s (%s): %s',
            $exceptionClass,
            $context,
            $donation->getUuid(),
            $paymentIntentId,
            $exception->getMessage(),
        ));

        return new JsonResponse([
            'error' => [
                'message' => $exception->getMessage(),
                'code' => $exception->getStripeCode(),
                'decline_code' => $exception->getDeclineCode(),
            ],
        ], 402);
    }
}
