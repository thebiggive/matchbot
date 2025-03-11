<?php

namespace MatchBot\Application\Security;

use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\PersonId;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;

class Security
{
    /**
     * @psalm-suppress PossiblyUnusedMethod - called by Container
     */
    public function __construct(private DonorAccountRepository $donorAccountRepository)
    {
    }

    private function maybeGetAuthenticatedDonorAccount(Request $request): ?DonorAccount
    {
        $donorId = $request->getAttribute(PersonWithPasswordAuthMiddleware::PERSON_ID_ATTRIBUTE_NAME);

        \assert($donorId instanceof PersonId);

        return $this->donorAccountRepository->findByPersonId($donorId);
    }

    /**
     * Returns the DonorAccount object for the authenticated donor, or throws.
     *
     * Currently only works if the PersonWithPasswordAuthMiddleware processed the request, which means we already
     * know there is an authenticated donor with a password set. In future we could consider running a suitable
     * middleware for all requests to process any JWT that the client chooses to send but not throw from the middleware
     * itself.
     *
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
