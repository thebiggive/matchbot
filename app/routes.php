<?php

declare(strict_types=1);

use MatchBot\Application\Actions\Donations;
use MatchBot\Application\Actions\Hooks;
use MatchBot\Application\Auth\DonationHookAuthMiddleware;
use MatchBot\Application\Auth\DonationPublicAuthMiddleware;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\FundRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->get('/', function (Request $request, Response $response) {
        // TODO scrap entity logic in temporary test endpoint
        $campaign = new Campaign();
        $campaign->setSalesforceId('a051r00001HoxCLAAZ');
        $campaign = $this->get(CampaignRepository::class)->pull($campaign);
        $this->get(FundRepository::class)->pullForCampaign($campaign);

        var_dump($campaign);

        $response->getBody()->write('Hello world!');
        return $response;
    });

    // TODO tidy up + implement CORS whitelist
    $corsOrigins = [
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

    $app->group('/v1', function (RouteCollectorProxy $versionGroup) {
        $versionGroup->post('/donations', Donations\Create::class); // Currently the only unauthenticated endpoint.

        $versionGroup->group('/donations/{donationId:[a-z0-9-]{36}}', function (RouteCollectorProxy $group) {
            $group->get('', Donations\Get::class);
            $group->put('', Donations\Cancel::class);
        })
            ->add(DonationPublicAuthMiddleware::class);

        $versionGroup->post('/hooks/donation/{donationId:[a-z0-9-]{36}}', Hooks\DonationUpdate::class)
            ->add(DonationHookAuthMiddleware::class);
    });

    $app->options('/{routes:.+}', function ($request, $response, $args) {
        return $response;
    });

    $app->add(function ($request, $handler) {
        $response = $handler->handle($request);
        return $response
            ->withHeader('Access-Control-Allow-Origin', 'http://localhost:4200') // TODO make dynamic
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
