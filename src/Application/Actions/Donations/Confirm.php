<?php

namespace MatchBot\Application\Actions\Donations;

use Doctrine\ORM\EntityManagerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Fees\Calculator;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\DonationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

class Confirm extends Action
{
    /**
     * Message excerpts that we expect to see sometimes from stripe on InvalidRequestExceptions. An exception
     * containing any of these strings should not generate an alarm.
     */
    public const EXPECTED_STRIPE_INVALID_REQUEST_MESSAGES = [
        'The provided PaymentMethod has failed authentication',
        'You must collect the security code (CVC) for this card from the cardholder before you can use it',
    ];

    public function __construct(
        LoggerInterface $logger,
        private DonationRepository $donationRepository,
        private StripeClient $stripeClient,
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
        foreach (self::EXPECTED_STRIPE_INVALID_REQUEST_MESSAGES as $expectedMessage) {
            if (str_contains($exception->getMessage(), $expectedMessage)) {
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
                $request->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            throw new HttpBadRequestException($request, 'Cannot parse request body as JSON');
        }
        \assert(is_array($requestBody));

        $pamentMethodId = $requestBody['stripePaymentMethodId'];
        \assert((is_string($pamentMethodId)));

        $this->entityManager->beginTransaction();

        $donation = $this->donationRepository->findAndLockOneBy(['uuid' => $args['donationId']]);
        if (! $donation) {
            throw new NotFoundException();
        }

        $paymentIntentId = $donation->getTransactionId();
        $paymentMethod = $this->stripeClient->paymentMethods->retrieve($pamentMethodId);

        if ($paymentMethod->type !== 'card') {
            throw new HttpBadRequestException($request, 'Confirm endpoint only supports card payments for now');
        }

        // documented at https://stripe.com/docs/api/payment_methods/object?lang=php
        // Contrary to what Stripes docblock says, in my testing 'brand' is strings like 'visa' or 'amex'. Not 'Visa' or
        // 'American Express'
        $cardBrand = $paymentMethod->card->brand;
        \assert(is_string($cardBrand));

        // two letter upper string, e.g. 'GB', 'US'.
        $cardCountry = $paymentMethod->card->country;
        \assert(is_string($cardCountry));
        if (! in_array($cardBrand, Calculator::STRIPE_CARD_BRANDS, true)) {
            throw new HttpBadRequestException($request, "Unrecognised card brand");
        }

        // at present if the following line was left out we would charge a wrong fee in some cases. I'm not happy with
        // that, would like to find a way to make it so if its left out we get an error instead - either by having
        // derive fees return a value, or making functions like Donation::getCharityFeeGross throw if called before it.
        $this->donationRepository->deriveFees($donation, $cardBrand, $cardCountry);

        $this->stripeClient->paymentIntents->update($paymentIntentId, [
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
            $this->stripeClient->paymentIntents->confirm($paymentIntentId, [
                'payment_method' => $pamentMethodId,
            ]);
        } catch (CardException $exception) {
            $exceptionClass = get_class($exception);
            $this->logger->info(sprintf(
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
                    'code' => $exception->getStripeCode(),
                    'decline_code' => $exception->getDeclineCode(),
                ],
            ], 402);
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
            $this->logger->info(sprintf(
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

        $updatedIntent = $this->stripeClient->paymentIntents->retrieve($paymentIntentId);

        $this->entityManager->flush();

        return new JsonResponse([
            'paymentIntent' => [
                'status' => $updatedIntent->status,
                'client_secret' => $updatedIntent->status === 'requires_action'
                    ?  $updatedIntent->client_secret
                    : null,
            ],
        ]);
    }
}
