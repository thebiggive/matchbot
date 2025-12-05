<?php

namespace MatchBot\Application\Actions;

use Fig\Http\Message\StatusCodeInterface;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class UpdatePaymentMethod extends Action
{
    public const array EXPECTED_STRIPE_NEW_CARD_MESSAGES = [
        "Your card's security code is incorrect.",
        'Your card number is incorrect.',
        'Your card was declined.',
        'Your card has expired.',
        'Your card does not support this type of purchase.',
        'Your card\'s expiration month is invalid.',
        'Invalid account.',
    ];

    #[Pure]
    public function __construct(
        private StripeClient $stripeClient,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        $customerId = $request->getAttribute(PersonManagementAuthMiddleware::PSP_ATTRIBUTE_NAME);
        \assert(is_string($customerId));

        $paymentMethodId = $args['payment_method_id'];
        \assert(is_string($paymentMethodId));

        /** @var array<array{id: string}> $allPaymentMethodsOfCustomer */
        $allPaymentMethodsOfCustomer = $this->stripeClient->customers->allPaymentMethods(
            $customerId,
        )->toArray()['data'];

        $allCustomersPaymentMethodIds = array_map(
            static fn(array $pm): string => $pm['id'],
            $allPaymentMethodsOfCustomer
        );

        if (!in_array($paymentMethodId, $allCustomersPaymentMethodIds, true)) {
            $this->logger->warning(
                "Refusing to update stripe payment method as not found for customer",
                compact('customerId', 'paymentMethodId')
            );

            return $this->respondWithData(
                $response,
                ['error' => 'Payment method not found'],
                StatusCodeInterface::STATUS_BAD_REQUEST
            );
        }

        $body = (string)$request->getBody();

        // We don't need to know the details inside the billing details - we are just a thin layer between the front end
        // and Stripe here.
        try {
            $newBillingDetails = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $_exception) {
            return $this->respondWithData(
                $response,
                ['error' => 'Invalid JSON in request body'],
                StatusCodeInterface::STATUS_BAD_REQUEST
            );
        }

        assert(is_array($newBillingDetails));

        try {
            // see https://stripe.com/docs/api/payment_methods/update
            /**
             * @psalm-suppress MixedArgumentTypeCoercion - up to the FE to supply the right params in `$newBillingDetails`
             */
            $this->stripeClient->paymentMethods->update($paymentMethodId, $newBillingDetails);
        } catch (ApiErrorException $e) {
            // Error message could be e.g. "Your card's security code is incorrect." in which case the donor
            // will not be able to update their card and can choose to delete it and add a new card for their
            // next donation. Donor will see the message in frontend.

            $exceptionClass = get_class($e);

            $isExpectedExceptionMessage = \array_any(
                self::EXPECTED_STRIPE_NEW_CARD_MESSAGES,
                fn(string $expectedMessage) => \str_contains($e->getMessage(), $expectedMessage)
            );

            $this->logger->log(
                level: $isExpectedExceptionMessage ? LogLevel::INFO : LogLevel::ERROR,
                message: "Failed to update payment method, $exceptionClass: " . $e->getMessage(),
                context: compact('customerId', 'paymentMethodId')
            );
            return $this->respondWithData($response, ['error' => $e->getMessage()], 400);
        }

        return $this->respondWithData($response, data: [], statusCode: StatusCodeInterface::STATUS_NO_CONTENT);
    }
}
