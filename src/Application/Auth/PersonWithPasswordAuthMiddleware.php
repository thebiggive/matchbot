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
        if (! IdentityToken::isComplete($this->jws)) {
            /** @var array<string> $serverParams */
            $serverParams = $request->getServerParams();
            // We've seen in CC23 rare attempts to e.g. update payment method with a token that's not complete,
            // probably due to state changes in another tab in the same session. Just warn about these so it
            // doesn't fire alerts.
            $this->logger->warning(
                'JWT error: not complete - request URI:' . $request->getUri()  . " referer:" .
                ($serverParams['HTTP_REFERER'] ?? 'no_referror')
            );
            $this->unauthorised($this->logger, false, $request);
        }
    }
}
