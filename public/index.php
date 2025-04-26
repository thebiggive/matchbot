<?php

declare(strict_types=1);

use MatchBot\Application\Handlers\HttpErrorHandler;
use MatchBot\Application\Handlers\ShutdownHandler;
use MatchBot\Application\Security\CorsMiddleware;
use MatchBot\Application\Settings;
use Psr\Log\LoggerInterface;
use Slim\Factory\AppFactory;
use Slim\Factory\ServerRequestCreatorFactory;
use Slim\ResponseEmitter;

$container = require __DIR__ . '/../bootstrap.php';

// Instantiate the app
AppFactory::setContainer($container);
$app = AppFactory::create();
$callableResolver = $app->getCallableResolver();

// Register routes
$routes = require __DIR__ . '/../app/routes.php';
$routes($app);

$displayErrorDetails = $container->get(Settings::class)->displayErrorDetails;

// Create Request object from globals
$serverRequestCreator = ServerRequestCreatorFactory::create();
$request = $serverRequestCreator->createServerRequestFromGlobals();

// Create Error Handler
$responseFactory = $app->getResponseFactory();
$appContainer = $app->getContainer();
\assert($appContainer !== null);

$errorHandler = new HttpErrorHandler(
    $callableResolver,
    $responseFactory,
    $appContainer->get(LoggerInterface::class),
);

// Create Shutdown Handler
$shutdownHandler = new ShutdownHandler($request, $errorHandler, $displayErrorDetails);
register_shutdown_function($shutdownHandler);

// Add Routing Middleware
$app->addRoutingMiddleware();

// Add Error Middleware
$errorMiddleware = $app->addErrorMiddleware($displayErrorDetails, false, false);
$errorMiddleware->setDefaultErrorHandler($errorHandler);

$app->add(new CorsMiddleware());

// Run App & Emit Response
$response = $app->handle($request);
$responseEmitter = new ResponseEmitter();
$responseEmitter->emit($response);
