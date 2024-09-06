<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions;

use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;

class GetPaymentMethods extends Action
{
    #[Pure]
    public function __construct(
        private StripeClient $stripeClient,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * @see PersonWithPasswordAuthMiddleware
     */
    protected function action(Request $request, Response $response, array $args): Response
    {
        // The route at `/people/{personId}/donations` validates that the donor has permission to act
        // as the person, and sets this attribute to the Stripe Customer ID based on JWS claims, all
        // in `PersonWithPasswordAuthMiddleware`.
        $customerId = $request->getAttribute(PersonManagementAuthMiddleware::PSP_ATTRIBUTE_NAME);
        \assert(is_string($customerId));

        $paymentMethods = $this->stripeClient->customers->allPaymentMethods(
            $customerId,
            ['type' => 'card'],
        );

        return $this->respondWithData($response, $paymentMethods);
    }
}
