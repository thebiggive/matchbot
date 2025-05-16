<?php

namespace MatchBot\Application\Actions\RegularGivingMandate;

use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Security\Security;
use MatchBot\Client\Stripe;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class CreateSetupIntent extends Action
{
    public function __construct(
        private Security $security,
        LoggerInterface $logger,
        private Stripe $stripe,
    ) {
        parent::__construct($logger);
    }

    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        $donor = $this->security->requireAuthenticatedDonorAccountWithPassword($request);

        $setupIntent = $this->stripe->createSetupIntent($donor->stripeCustomerId);

        return new JsonResponse([
            'setupIntent' => [
                'client_secret' => $setupIntent->client_secret,
            ]
        ]);
    }
}
