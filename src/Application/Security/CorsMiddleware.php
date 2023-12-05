<?php

namespace MatchBot\Application\Security;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CorsMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $givenOrigin = $request->getHeaderLine('Origin');
        $corsAllowedOrigin = 'https://donate.thebiggive.org.uk';
        $corsAllowedOrigins = [
            'http://localhost:4000', // Local via Docker SSR
            'http://localhost:4200', // Local via native `ng serve`
            'https://localhost:4200', // Local via native `ng serve --ssl`
            'https://donate-ecs-staging.thebiggivetest.org.uk', // ECS staging direct
            'https://donate-staging.thebiggivetest.org.uk', // ECS + S3 staging via CloudFront
            'https://donate-staging.thebiggive.global', // ECS + S3 production via CloudFront, temporary testing global alias
            'https://donate-ecs-regression.thebiggivetest.org.uk', // ECS regression direct
            'https://donate-regression.thebiggivetest.org.uk', // ECS + S3 regression via CloudFront
            'https://donate-ecs-production.thebiggive.org.uk', // ECS production direct
            'https://donate-production.thebiggive.org.uk', // ECS + S3 production via CloudFront
            'https://donate.thebiggive.org.uk', // ECS + S3 production via CloudFront, short alias to permit thru early '23.
            'https://donate.biggive.org', // ECS + S3 production via CloudFront, Feb-2023-onwards primary domain
        ];
        if (!empty($givenOrigin) && in_array($givenOrigin, $corsAllowedOrigins, true)) {
            $corsAllowedOrigin = $givenOrigin;
        }

        // Basic approach based on https://www.slimframework.com/docs/v4/cookbook/enable-cors.html
        // - adapted to allow for multiple potential origins per-MatchBot instance.
        return $handler->handle($request)
            ->withHeader('Access-Control-Allow-Origin', $corsAllowedOrigin)
            ->withHeader(
                'Access-Control-Allow-Headers',
                'X-Tbg-Auth, X-Requested-With, Content-Type, Accept, Origin, Authorization'
            )
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
    }
}
