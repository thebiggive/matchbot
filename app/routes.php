<?php

declare(strict_types=1);

use MatchBot\Application\Actions\Donations;
use MatchBot\Application\Actions\Hooks;
use MatchBot\Application\Actions\Status;
use MatchBot\Application\Auth\DonationHookAuthMiddleware;
use MatchBot\Application\Auth\DonationPublicAuthMiddleware;
use Psr\Http\Message\RequestInterface;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->get('/ping', Status::class);

    $app->group('/v1', function (RouteCollectorProxy $versionGroup) {
        // Current unauthenticated endpoint in the `/v1` group.
        $versionGroup->post('/donations', Donations\Create::class);

        $versionGroup->group('/donations/{donationId:[a-z0-9-]{36}}', function (RouteCollectorProxy $group) {
            $group->get('', Donations\Get::class);
            $group->put('', Donations\Update::class); // Includes cancelling.
        })
            ->add(DonationPublicAuthMiddleware::class);
    });

    $app->put('/hooks/donation/{donationId:.{36}}', Hooks\DonationUpdate::class)
        ->add(DonationHookAuthMiddleware::class);

    // Authenticated through Stripes SDK signature verification
    $app->post('/hooks/stripe', Hooks\StripeUpdate::class);

    $app->options('/{routes:.+}', function ($request, $response, $args) {
        return $response;
    });

    $app->add(function (RequestInterface $request, $handler) {
        $response = $handler->handle($request);

        $givenOrigin = $request->getHeaderLine('Origin');
        $corsAllowedOrigin = 'https://donate.thebiggive.org.uk';
        $corsAllowedOrigins = [
            'http://localhost:4000', // Local via Docker SSR
            'http://localhost:4200', // Local via native `ng serve`
            'https://donate-ecs-staging.thebiggivetest.org.uk', // ECS staging direct
            'https://donate-staging.thebiggivetest.org.uk', // ECS + S3 staging via CloudFront
            'https://donate-ecs-regression.thebiggivetest.org.uk', // ECS regression direct
            'https://donate-regression.thebiggivetest.org.uk', // ECS + S3 regression via CloudFront
            'https://donate-ecs-production.thebiggive.org.uk', // ECS production direct
            'https://donate-production.thebiggive.org.uk', // ECS + S3 production via CloudFront
            'https://donate.thebiggive.org.uk' // ECS + S3 production via CloudFront, short alias
        ];
        if (!empty($givenOrigin) && in_array($givenOrigin, $corsAllowedOrigins, true)) {
            $corsAllowedOrigin = $givenOrigin;
        }

        // Basic approach based on https://www.slimframework.com/docs/v4/cookbook/enable-cors.html
        // - adapted to allow for multiple potential origins per-MatchBot instance.
        return $response
            ->withHeader('Access-Control-Allow-Origin', $corsAllowedOrigin)
            ->withHeader(
                'Access-Control-Allow-Headers',
                'X-Tbg-Auth, X-Requested-With, Content-Type, Accept, Origin, Authorization'
            )
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
    });

    $app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{routes:.+}', function ($request, $response) {
        throw new HttpNotFoundException($request);
    });
};
