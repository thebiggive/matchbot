<?php

declare(strict_types=1);

use LosMiddleware\RateLimit\RateLimitMiddleware;
use MatchBot\Application\Actions\DeletePaymentMethod;
use MatchBot\Application\Actions\Donations;
use MatchBot\Application\Actions\GetPaymentMethods;
use MatchBot\Application\Actions\Hooks;
use MatchBot\Application\Actions\Status;
use MatchBot\Application\Auth\DonationPublicAuthMiddleware;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use Middlewares\ClientIp;
use Psr\Http\Message\RequestInterface;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->get('/ping', Status::class);

    // TODO once `/v2` is used by live Donate, support only that prefix.
    // We should think about whether Salesforce should be treated as a "v1 client"
    // still, probably while also discussing the value vs. complexity of introducing
    // a 1:1 relationship between Stripe customers and Salesforce contacts.
    $app->group('/v{version:[12]}', function (RouteCollectorProxy $versionGroup) {
        $versionGroup->post('/people/{personId:[a-z0-9-]{36}}/donations', Donations\Create::class)
            ->add(PersonManagementAuthMiddleware::class) // Runs last
            ->add(RateLimitMiddleware::class);

        $versionGroup->get('/people/{personId:[a-z0-9-]{36}}/payment_methods', GetPaymentMethods::class)
            ->add(PersonWithPasswordAuthMiddleware::class) // Runs last
            ->add(RateLimitMiddleware::class);

        $versionGroup->delete('/people/{personId:[a-z0-9-]{36}}/payment_methods/{payment_method_id:[a-zA-Z0-9_]{10,50}}', DeletePaymentMethod::class)
            ->add(PersonWithPasswordAuthMiddleware::class) // Runs last
            ->add(RateLimitMiddleware::class);

        $versionGroup->group('/donations/{donationId:[a-z0-9-]{36}}', function (RouteCollectorProxy $group) {
            $group->get('', Donations\Get::class);
            $group->put('', Donations\Update::class); // Includes cancelling.
        })
            ->add(DonationPublicAuthMiddleware::class);
    });

    // Authenticated through Stripe's SDK signature verification
    $app->post('/hooks/stripe', Hooks\StripeChargeUpdate::class);
    $app->post('/hooks/stripe-connect', Hooks\StripePayoutUpdate::class);

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
