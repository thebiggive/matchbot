<?php

declare(strict_types=1);

namespace MatchBot\Application\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response;
use Slim\Routing\Route;

class DonationPublicAuthMiddleware implements MiddlewareInterface
{
    /** @var LoggerInterface */
    private $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        /** @var $route Route */
        $route = $request->getAttribute('route');
        $donationId = $route->getArgument('donationId');

        if (!Token::check($donationId, $request->getHeaderLine('x-tbg-auth'), $this->logger)) {
            return new Response(401);
        }

        return $handler->handle($request);
    }
}
