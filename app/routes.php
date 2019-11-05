<?php

declare(strict_types=1);

use MatchBot\Application\Actions\Donations;
use MatchBot\Application\Actions\Hooks;
use MatchBot\Application\Auth\DonationHookAuthMiddleware;
use MatchBot\Application\Auth\DonationPublicAuthMiddleware;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return function (App $app) {
    $app->get('/', function (Request $request, Response $response) {
        // TODO scrap entity logic in temporary test endpoint
        $campaign = new Campaign();
        $campaign->setSalesforceId('a051r00001HoxCLAAZ');
        $campaign = $this->get(CampaignRepository::class)->pull($campaign);

        var_dump($campaign);

        $response->getBody()->write('Hello world!');
        return $response;
    });

    $app->post('/donations', Donations\Create::class); // Currently the only unauthenticated endpoint.

    $app->group('/donations/{donationId:[a-zA-Z0-9]{18}}', static function (RouteCollectorProxy $group) {
        $group->get('', Donations\Get::class);
        $group->put('', Donations\Cancel::class);
    })
        ->add(new DonationPublicAuthMiddleware());

    $app->post('/hooks/donation/{donationId:[a-zA-Z0-9]{18}}', Hooks\DonationUpdate::class)
        ->add(new DonationHookAuthMiddleware());
};
