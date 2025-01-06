<?php

namespace MatchBot\Application\Security;

use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\PersonId;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;

readonly class SecurityService {
    public function __construct(private DonorAccountRepository $donorAccountRepository)
    {

    }

    public function maybeGetAuthenticatedDonorAccount(Request $request): ?DonorAccount
    {
        $donorIdString = $request->getAttribute(PersonWithPasswordAuthMiddleware::PERSON_ID_ATTRIBUTE_NAME);
        assert(is_string($donorIdString));

        return $this->donorAccountRepository->findByPersonId(PersonId::of($donorIdString));
    }

    /**
     * @throws HttpForbiddenException
     */
    public function requireAuthenticatedDonorAccountWithPassword(Request $request): DonorAccount
    {
        $donor = $this->maybeGetAuthenticatedDonorAccount($request);

        if (! $donor) {
            throw new HttpForbiddenException($request);
        }

        return $donor;
    }
}