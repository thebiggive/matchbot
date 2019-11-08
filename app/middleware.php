<?php

declare(strict_types=1);

use Slim\App;

return function (App $app) {
    // TODO get CORS working properly
    $corsSettings = [
        'allow-credentials' => false, // set "Access-Control-Allow-Credentials" 👉 string "false" or "true".
        'allow-headers'      => ['*'], // ex: Content-Type, Accept, X-Requested-With
        'expose-headers'     => [],
        'origins'            => [
            'http://localhost:4000', // Local via Docker SSR
            'http://localhost:4200', // Local via native `ng serve`
            'https://donate-ecs-staging.thebiggivetest.org.uk', // ECS staging direct
            'https://donate-staging.thebiggivetest.org.uk', // ECS + S3 staging via CloudFront
            'https://donate-ecs-regression.thebiggivetest.org.uk', // ECS regression direct
            'https://donate-regression.thebiggivetest.org.uk', // ECS + S3 regression via CloudFront
            'https://donate-ecs-production.thebiggive.org.uk', // ECS production direct
            'https://donate-production.thebiggive.org.uk', // ECS + S3 production via CloudFront
            'https://donate.thebiggive.org.uk' // ECS + S3 production via CloudFront, short alias
        ],
        'methods'            => ['*'], // ex: GET, POST, PUT, PATCH, DELETE
        'max-age'            => 0,
    ];

    $app->add(new Medz\Cors\PSR\CorsMiddleware($corsSettings));
};
