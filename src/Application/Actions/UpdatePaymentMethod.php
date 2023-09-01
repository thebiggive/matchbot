<?php

namespace MatchBot\Application\Actions;

use Fig\Http\Message\StatusCodeInterface;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class UpdatePaymentMethod extends Action
{
    #[Pure]
    public function __construct(
        private StripeClient $stripeClient,
        LoggerInterface      $logger
    )
    {
        parent::__construct($logger);
    }

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

        $newBillingDetails = json_decode($body, true, 512, \JSON_THROW_ON_ERROR);

        assert(is_array($newBillingDetails));

        try {
            // see https://stripe.com/docs/api/payment_methods/update
            $this->stripeClient->paymentMethods->update($paymentMethodId, $newBillingDetails);
        } catch (ApiErrorException $e) {
            // Error message could be e.g. "Your card's security code is incorrect." in which case the donor
            // will not be able to update their card and can choose to delete it and add a new card for their
            // next donation. Donor will see the message in frontend.

            $this->logger->info(
                "Failed to update payment method, error: " . $e->getMessage(),
                compact('customerId', 'paymentMethodId')
            );
            return $this->respondWithData($response, ['error' => $e->getMessage()], 400);
        }

        return $this->respondWithData($response, data: [], statusCode: StatusCodeInterface::STATUS_NO_CONTENT);
    }
}
