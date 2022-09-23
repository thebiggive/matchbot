<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions;

use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
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
    protected function action(): Response
    {
        // The route at `/people/{personId}/donations` validates that the donor has permission to act
        // as the person, and sets this attribute to the Stripe Customer ID based on JWS claims, all
        // in `PersonWithPasswordAuthMiddleware`.
        $customerId = $this->request->getAttribute('pspId');

        $paymentMethods = $this->stripeClient->customers->allPaymentMethods(
            $customerId,
            ['type' => 'card'],
        );

        return $this->respondWithData($paymentMethods);
    }
}
