<?php

namespace MatchBot\Application\Actions\DonorAccount;

use MatchBot\Application\Actions\Action;
use MatchBot\Application\Security\Security;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationFundsService;
use MatchBot\Domain\PersonId;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpUnauthorizedException;

class ReturnAllDonationFunds extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private DonationFundsService $donationFundsService,
        private Security $security,
    ) {
        parent::__construct($logger);
    }

    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        $authedUser = $this->security->requireAuthenticatedDonorAccountWithPassword($request);
        $requestedUserId = PersonId::of((string) $args['personId']);

        if (! $authedUser->id()->equals($requestedUserId)) {
            throw new HttpUnauthorizedException($request);
        }

        $this->donationFundsService->refundFullBalanceToCustomer($authedUser);

        return $this->respondWithData($response, []);
    }
}
