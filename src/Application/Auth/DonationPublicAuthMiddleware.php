<?php

declare(strict_types=1);

namespace MatchBot\Application\Auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * @todo
 */
class DonationPublicAuthMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Route params? https://stackoverflow.com/a/39083045/2803757
//        var_dump($request->getAttribute('routeInfo')[2]);
    }
}
