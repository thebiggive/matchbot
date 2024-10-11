<?php

declare(strict_types=1);

use Los\RateLimit\RateLimitMiddleware;
use MatchBot\Application\Actions\Charities\UpdateCharityFromSalesforce;
use MatchBot\Application\Actions\DeletePaymentMethod;
use MatchBot\Application\Actions\UpdatePaymentMethod;
use MatchBot\Application\Actions\Donations;
use MatchBot\Application\Actions\DonorAccount;
use MatchBot\Application\Actions\GetPaymentMethods;
use MatchBot\Application\Actions\Hooks;
use MatchBot\Application\Actions\Status;
use MatchBot\Application\Auth\DonationPublicAuthMiddleware;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use MatchBot\Application\Auth\SalesforceAuthMiddleware;
use Middlewares\ClientIp;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteCollectorProxy;
use MatchBot\Application\Actions\RegularGivingMandate;

return function (App $app) {
    $app->get('/ping', Status::class);

    $app->group('/v1', function (RouteCollectorProxy $versionGroup) {
        // Provides real IP for e.g. rate limiter
        $ipMiddleware = getenv('APP_ENV') === 'local'
            ? new ClientIp()
            : (new ClientIp())->proxy([], ['X-Forwarded-For']);

        $versionGroup->post('/people/{personId:[a-z0-9-]{36}}/donations', Donations\Create::class)
            ->add(PersonManagementAuthMiddleware::class)
            ->add($ipMiddleware)
            ->add(RateLimitMiddleware::class);

        $versionGroup->group('/donations/{donationId:[a-z0-9-]{36}}', function (RouteCollectorProxy $group) {
            $group->get('', Donations\Get::class);
            $group->put('', Donations\Update::class); // Includes cancelling.
            $group->post('/confirm', Donations\Confirm::class);
        })
            ->add(DonationPublicAuthMiddleware::class);

        $versionGroup->post('/people/{personId:[a-z0-9-]{36}}/donor-account', DonorAccount\Create::class)
            ->add(PersonWithPasswordAuthMiddleware::class) // Runs last
            ->add($ipMiddleware)
            ->add(RateLimitMiddleware::class);

        // TODO Migrate Donate to the less confusing endpoint above, then delete this one.
        $versionGroup->post('/{personId:[a-z0-9-]{36}}/donor-account', DonorAccount\Create::class)
            ->add(PersonWithPasswordAuthMiddleware::class) // Runs last
            ->add($ipMiddleware)
            ->add(RateLimitMiddleware::class);

        // TODO Discuss moving this to e.g. /people/{personId}/mandates for consistency & easier understanding
        // of the available endpoints.
        $versionGroup->get('/regular-giving/my-donation-mandates', RegularGivingMandate\GetAllForUser::class)
            ->add(PersonWithPasswordAuthMiddleware::class)
            ->add($ipMiddleware)
            ->add(RateLimitMiddleware::class);

        $versionGroup->get('/people/{personId:[a-z0-9-]{36}}/donations', Donations\GetAllForUser::class)
            ->add(PersonWithPasswordAuthMiddleware::class)
            ->add($ipMiddleware);

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
            ->add(RateLimitMiddleware::class);
    });

    // Authenticated through Stripe's SDK signature verification
    $app->post('/hooks/stripe', Hooks\StripePaymentsUpdate::class);
    $app->post('/hooks/stripe-connect', Hooks\StripePayoutUpdate::class);

    // Requests from Salesforce

    $app->post(
        '/hooks/charities/{salesforceId:[a-zA-Z0-9]{18}}/update-required',
        UpdateCharityFromSalesforce::class
    )
        ->add(SalesforceAuthMiddleware::class);

    $app->options(
        '/{routes:.+}',
        fn (RequestInterface $_req, ResponseInterface $resp, array $_args): ResponseInterface => $resp
    );

    $app->map(
        ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'],
        '/{routes:.+}',
        fn (ServerRequestInterface $req, ResponseInterface $_resp) => throw new HttpNotFoundException($req)
    );
};
