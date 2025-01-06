<?php

namespace MatchBot\Application\Actions\RegularGivingMandate;

use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use MatchBot\Client\Stripe;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\PersonId;
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
        private DonorAccountRepository $donorAccountRepository,
    ) {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {
        $donorIdString = $request->getAttribute(PersonWithPasswordAuthMiddleware::PERSON_ID_ATTRIBUTE_NAME);
        assert(is_string($donorIdString));

        $donor = $this->donorAccountRepository->findByPersonId(PersonId::of($donorIdString));

        \assert($donor !== null);

        $customerSession = $this->stripeClient->createCustomerSession($donor->stripeCustomerId);

        return new JsonResponse(['stripeSessionSecret' =>  $customerSession->client_secret]);
    }
}
