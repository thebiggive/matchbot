<?php

use LosMiddleware\RateLimit\RateLimitMiddleware;
use MatchBot\Application\Auth\DonationRecaptchaMiddleware;
use MatchBot\IntegrationTests\IntegrationTest;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

if (! in_array(getenv('APP_ENV'), ['local', 'test'])) {
    throw new \Exception("Don't run integration tests in live!");
}
