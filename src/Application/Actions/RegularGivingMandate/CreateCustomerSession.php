<?php

namespace MatchBot\Application\Actions\RegularGivingMandate;

use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Security\Security;
use MatchBot\Client\Stripe;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

/**
 * Creates a stripe customer session for use with regular giving
 */
class CreateCustomerSession extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private Stripe $stripeClient,
        private Security $security,
    ) {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {
        $donor = $this->security->requireAuthenticatedDonorAccountWithPassword($request);

        $customerSession = $this->stripeClient->createCustomerSession($donor->stripeCustomerId);

        return new JsonResponse(['stripeSessionSecret' =>  $customerSession->client_secret]);
    }
}
