<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions;

use Fig\Http\Message\StatusCodeInterface;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\InvalidRequestException;
use Stripe\StripeClient;

class DeletePaymentMethod extends Action
{
    #[Pure]
    public function __construct(
        private StripeClient $stripeClient,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * @throws ApiErrorException
     * @see PersonWithPasswordAuthMiddleware
     */
    protected function action(Request $request, Response $response, array $args): Response
    {
        $customerId = $request->getAttribute('pspId');
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
                "Refusing to delete stripe payment method as not found for customer",
                compact('customerId', 'paymentMethodId')
            );

            return $this->respondWithData(
                $response,
                ['error' => 'Payment method not found'],
                StatusCodeInterface::STATUS_BAD_REQUEST
            );
        }

        try {
            $this->stripeClient->paymentMethods->detach($paymentMethodId);
        } catch (InvalidRequestException $e) {
            $this->logger->error(
                "Failed to delete payment method, error: " . $e->getMessage(),
                compact('customerId', 'paymentMethodId')
            );
            return $this->respondWithData($response, ['error' => "Could not delete payment method"], 400);
        }

        return $this->respondWithData($response, data: [], statusCode: StatusCodeInterface::STATUS_NO_CONTENT);
    }
}
