<?php

namespace MatchBot\Application;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Add to routes we want to cache for (currently) up 1 minute each.
 */
class CacheableResponseMiddleware implements MiddlewareInterface
{
    private const int CACHE_SECONDS = 60; // 1 minute

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        return $response
            ->withHeader('Cache-Control', 'public, max-age=' . self::CACHE_SECONDS)
            ->withHeader('Expires', gmdate('D, d M Y H:i:s', time() + self::CACHE_SECONDS) . ' GMT');
    }
}
