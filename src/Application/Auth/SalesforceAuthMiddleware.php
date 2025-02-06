<?php

declare(strict_types=1);

namespace MatchBot\Application\Auth;

use Fig\Http\Message\StatusCodeInterface;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Assertion;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response;

/**
 * This middleware requires that a request is from our own Salesforce instance, based on a shared secret. Salesforce
 * is a source of truth for much of our data so requests from it may be trusted with wide powers to modify matchbot
 * state.
 *
 * Based on \BigGive\Identity\Client\Mailer
 */
readonly class SalesforceAuthMiddleware implements MiddlewareInterface
{
    #[Pure]
    public function __construct(
        /** @var non-empty-string $sfApiKey */
        #[\SensitiveParameter]
        private string $sfApiKey,
        private LoggerInterface $logger,
    ) {
        Assertion::notEmpty($this->sfApiKey);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->verify($request)) {
            return $this->unauthorised($this->logger);
        }

        return $handler->handle($request);
    }

    protected function unauthorised(LoggerInterface $logger): ResponseInterface
    {
        $logger->warning('Unauthorised');

        /** @var ResponseInterface $response */
        $response = new Response(StatusCodeInterface::STATUS_UNAUTHORIZED);
        $response->getBody()->write(json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function verify(ServerRequestInterface $request): bool
    {
        $givenHash = $request->getHeaderLine('x-send-verify-hash');

        $expectedHash = hash_hmac(
            'sha256',
            trim((string) $request->getBody()),
            $this->sfApiKey
        );

        return ($givenHash === $expectedHash);
    }
}
