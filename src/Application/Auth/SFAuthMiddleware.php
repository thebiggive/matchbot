<?php

namespace MatchBot\Application\Auth;

use MatchBot\Application\Assertion;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Routing\RouteContext;

readonly class SFAuthMiddleware implements MiddlewareInterface
{
    use ErrorTrait;

    public function __construct(private string $secret, private \DateTimeImmutable $now, private LoggerInterface $logger)
    {
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeContext = RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        Assertion::notNull($route);

        $timeStep = 60; // Must match Salesforce

        $currentCounter = floor($this->now->getTimestamp() / $timeStep);
        $receivedRecordId = $route->getArgument('donationId');

        $receivedToken = $request->getQueryParams()['t'] ?? null;

        // Check; current, previous, and next windows
        for ($i = -1; $i <= 1; $i++) {
            $testCounter = $currentCounter + $i;
            $payload = "$receivedRecordId|$testCounter";

            $expectedToken = \hash_hmac(algo: 'sha256', data: $payload, key: $this->secret, binary: false);

            if ($receivedToken === $expectedToken) {
                return $handler->handle($request);
            }
        }

        $this->unauthorised($this->logger, false, $request);
    }
}
