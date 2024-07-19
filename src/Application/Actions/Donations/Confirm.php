<?php

namespace MatchBot\Application\Actions\Donations;

use Doctrine\ORM\EntityManagerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Assertion;
use MatchBot\Application\Fees\Calculator;
use MatchBot\Application\LazyAssertionException;
use MatchBot\Application\Messenger\DonationUpdated;
use MatchBot\Client\NotFoundException;
use MatchBot\Client\Stripe;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Symfony\Component\Messenger\Envelope;

class Confirm extends Action
{
    /**
     * Message excerpts that we expect to see sometimes from stripe on InvalidRequestExceptions. An exception
     * containing any of these strings should not generate an alarm.
     */
    public const EXPECTED_STRIPE_INVALID_REQUEST_MESSAGES = [
        'The provided PaymentMethod has failed authentication',
        'You must collect the security code (CVC) for this card from the cardholder before you can use it',

        // When a donation is cancelled we update it to cancelled in the DB, which stops it being confirmed later. But
        // we can still get this error if the cancellation is too late to stop us attempting to confirm.
        // phpcs:ignore
        'This PaymentIntent\'s payment_method could not be updated because it has a status of canceled. You may only update the payment_method of a PaymentIntent with one of the following statuses: requires_payment_method, requires_confirmation, requires_action.',
    ];

    public function __construct(
        LoggerInterface $logger,
        private DonationRepository $donationRepository,
        private Stripe $stripe,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct($logger);
    }

    /**
     * InvalidRequestException can have various possible messages. If it's one we've seen before that we don't believe
     * indicates a bug or failure in matchbot then we just send an error message to the client. If it's something we
     * haven't seen before or didn't expect then we will also generate an alarm for Big Give devs to deal with.
     */
    private function errorMessageFromStripeIsExpected(InvalidRequestException $exception): bool
    {
        $exceptionMessage = $exception->getMessage();

        foreach (self::EXPECTED_STRIPE_INVALID_REQUEST_MESSAGES as $expectedMessage) {
            if (str_contains($exceptionMessage, $expectedMessage)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Called to confirm that the donor wishes to make a donation immediately. We will tell Stripe to take their money
     * using the payment method provided. Does not update the donation status, that only gets updated when stripe calls
     * back to say the money is taken.
     */
    protected function action(Request $request, Response $response, array $args): Response
    {
        try {
            $requestBody = json_decode(
                $request->getBody()->getContents(),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            throw new HttpBadRequestException($request, 'Cannot parse request body as JSON');
        }
        \assert(is_array($requestBody));

        $paymentMethodId = $requestBody['stripePaymentMethodId'];

        $this->entityManager->beginTransaction();

        $donation = $this->donationRepository->findAndLockOneBy(['uuid' => $args['donationId']]);
        if (! $donation) {
            throw new NotFoundException();
        }

        if (!is_string($paymentMethodId) || trim($paymentMethodId) === '') {
            $donationUUID = $donation->getId();
            $this->logger->warning(
                <<<EOF
Donation Confirmation attempted with missing payment method id "$paymentMethodId" for Donation $donationUUID
EOF
            );
            throw new HttpBadRequestException($request, "stripePaymentMethodId required");
        }
        \assert($paymentMethodId !== ""); // required to call updatePaymentMethodBillingDetail

        try {
            $donation->assertIsReadyToConfirm();
        } catch (LazyAssertionException $exception) {
            $message = $exception->getMessage();
            $this->logger->warning($message);

            throw new HttpBadRequestException($request, $message);
        }

        $paymentIntentId = $donation->getTransactionId();
        try {
            $paymentMethod = $this->stripe->updatePaymentMethodBillingDetail($paymentMethodId, $donation);
        } catch (CardException $cardException) {
            $this->entityManager->rollback();

            return $this->handleCardException(
                context: 'updatePaymentMethodBillingDetail',
                exception: $cardException,
                donation: $donation,
                paymentIntentId: $paymentIntentId,
            );
        } catch (ApiErrorException $exception) {
            $this->entityManager->rollback();

            if (str_contains($exception->getMessage(), "You must collect the security code (CVC) for this card")) {
                $this->logger->warning(sprintf(
                    'Stripe %s on Confirm updatePaymentMethodBillingDetail for donation %s (%s): %s',
                    get_class($exception),
                    $donation->getUuid(),
                    $paymentIntentId,
                    $exception->getMessage(),
                ));

                return new JsonResponse([
                    'error' => [
                        'message' => 'Cannot confirm donation; card security code not collected',
                        'code' => $exception->getStripeCode()
                    ],
                ], 402);
            }

            throw $exception;
        }

        if ($paymentMethod->type !== 'card') {
            throw new HttpBadRequestException($request, 'Confirm endpoint only supports card payments for now');
        }

        /**
         * This is not technically true - at runtime this is a StripeObject instance, but the behaviour seems to be as
         * documented in the Card class. Stripe SDK is interesting. Without this annotation we would have SA errors on
         * ->brand and ->country
         * @var Card $card
         */
        $card = $paymentMethod->card;

        // documented at https://stripe.com/docs/api/payment_methods/object?lang=php
        // Contrary to what Stripes docblock says, in my testing 'brand' is strings like 'visa' or 'amex'. Not 'Visa' or
        // 'American Express'
        $cardBrand = $card->brand;

        // two letter upper string, e.g. 'GB', 'US'.
        $cardCountry = $card->country;
        \assert(is_string($cardCountry));
        if (! in_array($cardBrand, Calculator::STRIPE_CARD_BRANDS, true)) {
            throw new HttpBadRequestException($request, "Unrecognised card brand");
        }

        // at present if the following line was left out we would charge a wrong fee in some cases. I'm not happy with
        // that, would like to find a way to make it so if its left out we get an error instead - either by having
        // derive fees return a value, or making functions like Donation::getCharityFeeGross throw if called before it.
        $donation->deriveFees($cardBrand, $cardCountry);

        $this->stripe->updatePaymentIntent($paymentIntentId, [
            // only setting things that may need to be updated at this point.
            'metadata' => [
                'stripeFeeRechargeGross' => $donation->getCharityFeeGross(),
                'stripeFeeRechargeNet' => $donation->getCharityFee(),
                'stripeFeeRechargeVat' => $donation->getCharityFeeVat(),
            ],
            // See https://stripe.com/docs/connect/destination-charges#application-fee
            // Update the fee amount in case the final charge was from
            // e.g. a Non EU / Amex card where fees are varied.
            'application_fee_amount' => $donation->getAmountToDeductFractional(),
            // Note that `on_behalf_of` is set up on create and is *not allowed* on update.
        ]);

        try {
            // looks like sometimes $paymentIntentId and $paymentMethodId are for different customers.
            $updatedIntent = $this->stripe->confirmPaymentIntent($paymentIntentId, [
                'payment_method' => $paymentMethodId,
            ]);
        } catch (CardException $exception) {
            $this->entityManager->rollback();

            return $this->handleCardException(
                context: 'confirmPaymentIntent',
                exception: $exception,
                donation: $donation,
                paymentIntentId: $paymentIntentId,
            );
        } catch (InvalidRequestException $exception) {
            // We've seen card test bots, and no humans, try to reuse payment methods like this as of Oct '23. For now
            // we want to log it as a warning, so we can see frequency on a dashboard but don't get alarms.
            // The full Stripe message ($exception->getMessage()) we've seen is e.g.:
            // "The provided PaymentMethod was previously used with a PaymentIntent without Customer attachment,
            // shared with a connected account without Customer attachment, or was detached from a Customer. It may
            // not be used again. To use a PaymentMethod multiple times, you must attach it to a Customer first."
            $paymentMethodReuseAttempted = (
                str_contains($exception->getMessage(), 'The provided PaymentMethod was previously used')
            );
            if ($paymentMethodReuseAttempted) {
                $this->logger->warning(sprintf(
                    'Stripe InvalidRequestException on Confirm for donation %s (%s): %s',
                    $donation->getUuid(),
                    $paymentIntentId,
                    $exception->getMessage(),
                ));

                $this->entityManager->rollback();

                return new JsonResponse([
                    'error' => [
                        'message' => 'Payment method cannot be used again',
                        'code' => $exception->getStripeCode(),
                    ],
                ], 402);
            }

            if (!$this->errorMessageFromStripeIsExpected($exception)) {
                throw $exception;
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
        }

        // Assuming Stripe calls worked, commit any changes that `deriveFees()` made to the EM-tracked `$donation`.
        $this->entityManager->flush();
        $this->entityManager->commit();

        $this->bus->dispatch(new Envelope(DonationUpdated::fromDonation($donation)));

        return new JsonResponse([
            'paymentIntent' => [
                'status' => $updatedIntent->status,
                'client_secret' => $updatedIntent->status === 'requires_action'
                    ?  $updatedIntent->client_secret
                    : null,
            ],
        ]);
    }

    public function randomString(): string
    {
        return substr(Uuid::uuid4()->toString(), 0, 15);
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
