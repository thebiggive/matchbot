<?php

use LosMiddleware\RateLimit\RateLimitMiddleware;
use MatchBot\Application\Auth\DonationRecaptchaMiddleware;
use MatchBot\IntegrationTests\IntegrationTest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

if (! in_array(getenv('APP_ENV'), ['local', 'test'])) {
    throw new \Exception("Don't run integration tests in live!");
}

$noOpMiddlware = new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
};

$container = require __DIR__ . '/../bootstrap.php';
IntegrationTest::setContainer($container);
$container->set(DonationRecaptchaMiddleware::class, $noOpMiddlware);
$container->set(RateLimitMiddleware::class, $noOpMiddlware);

// Instantiate the app
AppFactory::setContainer($container);
$app = AppFactory::create();

// Register routes
$routes = require __DIR__ . '/../app/routes.php';
$routes($app);

IntegrationTest::setApp($app);
