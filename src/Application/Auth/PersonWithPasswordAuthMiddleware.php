<?php

declare(strict_types=1);

namespace MatchBot\Application\Auth;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Checks an Identity service JWT is valid and, if so and one exists, adds the person ID
 * to the request as an extra attribute 'pspId'.
 *
 * Permits only true "complete" value in token claims. Use this for anything that should
 * be returned only to those who've set a password and so expect e.g. their saved payment
 * methods to be available longer term.
 */
class PersonWithPasswordAuthMiddleware extends PersonManagementAuthMiddleware
{
    protected function checkCompleteness(ServerRequestInterface $request): void
    {
        if (!$this->token->isComplete($this->jws)) {
            $this->logger->error('JWT error: not complete - request URI:' . $request->getUri()  . " referer:" . $request->getServerParams()['HTTP_REFERER'] ?? 'no_referror');
            $this->unauthorised($this->logger, false, $request);
        }
    }
}
