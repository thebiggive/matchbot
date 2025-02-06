<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\DonorAccount;

use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Security\Security;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\PersonId;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpUnauthorizedException;

class Get extends Action
{
    #[Pure]
    public function __construct(
        private DonorAccountRepository $donorAccountRepository,
        LoggerInterface $logger,
        private Security $security,
    ) {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {
        $authedUser = $this->security->requireAuthenticatedDonorAccountWithPassword($request);
        $requestedUserId = PersonId::of((string) $args['personId']);

        if (! $authedUser->id()->equals($requestedUserId)) {
            throw new HttpUnauthorizedException($request);
        }

        $donorAccount = $this->donorAccountRepository->findByPersonId($requestedUserId) ??
            throw new DomainRecordNotFoundException('Donor Account not found');

        return $this->respondWithData($response, $donorAccount->toFrontEndApiModel());
    }
}
