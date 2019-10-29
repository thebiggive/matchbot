<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app) {
    $app->get('/', function (Request $request, Response $response) {
        // TODO scrap temporary entity repo test
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->get(\Doctrine\ORM\EntityManagerInterface::class);
        $repo = $em->getRepository(\MatchBot\Domain\CampaignFunding::class);
        $campaign = $em->find(\MatchBot\Domain\Campaign::class, 1);

        $em->transactional(function($em) use ($repo, $campaign) {
            var_dump($repo->getAvailableFundings($campaign));
        });

        $response->getBody()->write('Hello world!');
        return $response;
    });
};
