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
 *
 * Permits either value for "complete" in token claims. This makes the middleware suitable
 * for authenticating e.g. non-guest donation creates, which are allowed for both new/anonymous
 * donors with Person IDs and those who've previously set a password.
 */
class PersonManagementAuthMiddleware implements MiddlewareInterface
{
    use ErrorTrait;

    protected ?string $jws = null;

    #[Pure]
    public function __construct(
        protected IdentityToken $token,
        protected LoggerInterface $logger
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
        $this->jws = $request->getHeaderLine('x-tbg-auth');

        if (empty($this->jws)) {
            $this->logger->info('Security: No JWT provided');
            $this->unauthorised($this->logger, true, $request);
        }

        if (!$this->token->check($personId, $this->jws, $this->logger)) {
            $this->logger->info(sprintf('Security: JWT check failed for person ID %s', $personId));
            $this->unauthorised($this->logger, false, $request);
        }

        $this->checkCompleteness($request);

        return $handler->handle($request->withAttribute('pspId', IdentityToken::getPspId($this->jws)));
    }

    protected function checkCompleteness(ServerRequestInterface $request): void
    {
        // No-op: we allow both values for this base middleware. This should be extended
        // by middlewares that have tighter requirements.
    }
}
