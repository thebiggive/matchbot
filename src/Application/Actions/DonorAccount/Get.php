<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\DonorAccount;

use Assert\Assertion;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\PersonId;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Slim\Exception\HttpUnauthorizedException;

class Get extends Action
{
    #[Pure]
    public function __construct(
        private DonorAccountRepository $donorAccountRepository,
        LoggerInterface $logger,
    ) {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {

        $authedUserId = $request->getAttribute(PersonWithPasswordAuthMiddleware::PERSON_ID_ATTRIBUTE_NAME);
        \assert(is_string($authedUserId));

        $authedUserId = PersonId::of($authedUserId); // I'm failing to understand why this line is needed,
        // we have similar without it in \MatchBot\Application\Actions\RegularGivingMandate\GetAllForUser::action

        // \assert($authedUserId instanceof PersonId);

        Assertion::keyExists($args, "personId");

        $requestedUserId = PersonId::of((string) $args['personId']);

        if (! $authedUserId->equals($requestedUserId)) {
            throw new HttpUnauthorizedException($request);
        }

        $donorAccount = $this->donorAccountRepository->findByPersonId($requestedUserId) ??
            throw new DomainRecordNotFoundException('Donor Account not found');

        return $this->respondWithData($response, $donorAccount->toFrontEndApiModel());
    }
}
