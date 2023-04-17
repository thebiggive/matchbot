<?php

declare(strict_types=1);

use LosMiddleware\RateLimit\RateLimitMiddleware;
use MatchBot\Application\Actions\DeletePaymentMethod;
use MatchBot\Application\Actions\UpdatePaymentMethod;
use MatchBot\Application\Actions\Donations;
use MatchBot\Application\Actions\GetPaymentMethods;
use MatchBot\Application\Actions\Hooks;
use MatchBot\Application\Actions\Status;
use MatchBot\Application\Auth\DonationPublicAuthMiddleware;
use MatchBot\Application\Auth\DonationRecaptchaMiddleware;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use Middlewares\ClientIp;
use Psr\Http\Message\RequestInterface;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->get('/ping', Status::class);

    $app->group('/v1', function (RouteCollectorProxy $versionGroup) {
        // Provides real IP for reCAPTCHA
        $ipMiddleware = getenv('APP_ENV') === 'local'
            ? new ClientIp()
            : (new ClientIp())->proxy([], ['X-Forwarded-For']);

        // Current unauthenticated endpoint in the `/v1` group. Middlewares run in reverse
        // order when chained this way – so we check rate limits first, then get the real
        // IP in an attribute for reCAPTCHA sending, then check the captcha.
        $versionGroup->post('/donations', Donations\Create::class)
            ->add(DonationRecaptchaMiddleware::class) // Runs last
            ->add($ipMiddleware)
            ->add(RateLimitMiddleware::class);

        $versionGroup->group('/donations/{donationId:[a-z0-9-]{36}}', function (RouteCollectorProxy $group) {
            $group->get('', Donations\Get::class);
            $group->put('', Donations\Update::class); // Includes cancelling.
        })
            ->add(DonationPublicAuthMiddleware::class);

        $versionGroup->post('/people/{personId:[a-z0-9-]{36}}/donations', Donations\Create::class)
            ->add(PersonManagementAuthMiddleware::class)
            ->add($ipMiddleware)
            ->add(RateLimitMiddleware::class);

        $versionGroup->group(
            '/people/{personId:[a-z0-9-]{36}}/payment_methods',
            function (RouteCollectorProxy $paymentMethodsGroup) {
                $paymentMethodUriSuffixPattern = '/{payment_method_id:[a-zA-Z0-9_]{10,50}}';

                $paymentMethodsGroup->get('', GetPaymentMethods::class);
                $paymentMethodsGroup->delete($paymentMethodUriSuffixPattern, DeletePaymentMethod::class);
                $paymentMethodsGroup->put("$paymentMethodUriSuffixPattern/billing_details", UpdatePaymentMethod::class);
            }
        )
            ->add(PersonWithPasswordAuthMiddleware::class) // Runs last
            ->add($ipMiddleware)
            ->add(RateLimitMiddleware::class);
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
