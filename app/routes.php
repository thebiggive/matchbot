<?php

declare(strict_types=1);

use MatchBot\Domain\Campaign;
use MatchBot\Domain\CampaignRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

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
};
