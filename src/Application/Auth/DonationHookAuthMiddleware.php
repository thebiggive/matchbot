<?php

declare(strict_types=1);

namespace MatchBot\Application\Auth;

use JetBrains\PhpStorm\Pure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

class DonationHookAuthMiddleware implements MiddlewareInterface
{
    use ErrorTrait;

    #[Pure]
    public function __construct(
        protected LoggerInterface $logger
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->verify($request)) {
            return $this->unauthorised($this->logger, false);
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
            trim((string) $request->getBody()),
            getenv('WEBHOOK_DONATION_SECRET')
        );

        return ($givenHash === $expectedHash);
    }
}
