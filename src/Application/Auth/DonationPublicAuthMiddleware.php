<?php

declare(strict_types=1);

namespace MatchBot\Application\Auth;

use JetBrains\PhpStorm\Pure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Routing\RouteContext;

class DonationPublicAuthMiddleware implements MiddlewareInterface
{
    use ErrorTrait;

    #[Pure]
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeContext = RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $donationId = $route->getArgument('donationId');
        $jws = $request->getHeaderLine('x-tbg-auth');

        if (empty($jws)) {
            $this->logger->info('Security: No JWT provided');
            $this->unauthorised($this->logger, true, $request);
        }

        if (!Token::check($donationId, $jws, $this->logger)) {
            $this->unauthorised($this->logger, false, $request);
        }

        return $handler->handle($request);
    }
}
