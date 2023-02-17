<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions;

use Fig\Http\Message\StatusCodeInterface;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * @psalm-suppress PropertyNotSetInConstructor - this is about the request, response, and args properties from the
 * parent class. @todo consider refactoring parent class and its 7 children to fix this issue as done in Identity
 * - see https://github.com/thebiggive/identity/commit/cc013a32f8d5a8a6822c8a52524796225d32b59c
 */
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
    protected function action(): Response
    {
        $customerId = $this->request->getAttribute('pspId');
        \assert(is_string($customerId));

        $paymentMethodId = $this->args['payment_method_id'];
        \assert(is_string($paymentMethodId));

        $this->stripeClient->customers->deleteSource($customerId, $paymentMethodId);

        return $this->respondWithData(data: [], statusCode: StatusCodeInterface::STATUS_NO_CONTENT);
    }
}
