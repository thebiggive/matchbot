<?php

declare(strict_types=1);

namespace MatchBot\Application\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class DonationHookAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->login($request)) {
            return new Response(401);
        }

        return $handler->handle($request);
    }

    /**
     * Check the user credentials and return the username or false.
     *
     * @param ServerRequestInterface $request
     * @return bool
     */
    private function login(ServerRequestInterface $request): bool
    {
        $givenHash = $request->getHeaderLine('X-Webhook-Verify-Hash');

        $expectedHash = hash_hmac(
            'sha256',
            trim($request->getBody()->getContents()),
            getenv('CHARITY_CHECKOUT_DONATION_SECRET')
        );

        return ($givenHash === $expectedHash);
    }
}
