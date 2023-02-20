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

        // this is throwing a 500 with "No such source: 'pm_xyz'" when testing on my local.
        // I want to see what it does on staging to understand if it's an issue specific to the local environment.
        $this->stripeClient->customers->deleteSource($customerId, $paymentMethodId);

        return $this->respondWithData($response, data: [], statusCode: StatusCodeInterface::STATUS_NO_CONTENT);
    }
}
