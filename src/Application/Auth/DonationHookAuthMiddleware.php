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
        if (!$this->verify($request)) {
            return new Response(401);
        }

        return $handler->handle($request);
    }

    /**
     * @param ServerRequestInterface $request
     * @return bool Whether the verification hash header value validates the given request body.
     */
    private function verify(ServerRequestInterface $request): bool
    {
        $givenHash = $request->getHeaderLine('x-webhook-verify-hash');

        $expectedHash = hash_hmac(
            'sha256',
            trim($request->getBody()->getContents()),
            getenv('WEBHOOK_DONATION_SECRET')
        );

        return ($givenHash === $expectedHash);
    }
}
