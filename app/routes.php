<?php

declare(strict_types=1);

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;

return function (App $app) {
    $app->get('/', function (Request $request, Response $response) {
        /** @var PDO $pdo */
        $pdo = $this->get(PDO::class);

        $response->getBody()->write('Hello world! - PDO code: ' . $pdo->errorCode());
        return $response;
    });
};
