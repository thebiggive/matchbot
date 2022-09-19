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

/**
 * Checks an Identity service JWT is valid and, if so and one exists, adds the person ID
 * to the request as an extra attribute 'pspId'.
 */
class PersonManagementAuthMiddleware implements MiddlewareInterface
{
    use ErrorTrait;

    #[Pure]
    public function __construct(
        private IdentityToken $token,
        private LoggerInterface $logger
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $routeContext = RouteContext::fromRequest($request);
        $route = $routeContext->getRoute();
        if (!$route) {
            $this->logger->info('Security: No route in context');
            $this->unauthorised($this->logger, false, $request);
        }

        $personId = $route->getArgument('personId');
        $jws = $request->getHeaderLine('x-tbg-auth');

        if (empty($jws)) {
            $this->logger->info('Security: No JWT provided');
            $this->unauthorised($this->logger, true, $request);
        }

        if (!$this->token->check($personId, $jws, $this->logger)) {
            $this->logger->info(sprintf('Security: JWT check failed for person ID %s', $personId));
            $this->unauthorised($this->logger, false, $request);
        }

        return $handler->handle($request->withAttribute('pspId', IdentityToken::getPspId($jws)));
    }
}
