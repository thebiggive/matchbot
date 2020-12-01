<?php

declare(strict_types=1);

namespace MatchBot\Application\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Routing\RouteContext;

class DonationPublicAuthMiddleware implements MiddlewareInterface
{
    use ErrorTrait;

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeContext = RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        $donationId = $route->getArgument('donationId');
        $jws = $request->getHeaderLine('x-tbg-auth');

        if (empty($jws)) {
            $this->logger->info('No JWT provided');
            return $this->unauthorised($this->logger, true);
        }

        if (!Token::check($donationId, $jws, $this->logger)) {
            return $this->unauthorised($this->logger, false);
        }

        return $handler->handle($request);
    }
}
