<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use MatchBot\Domain\Donation;
use Slim\CallableResolver;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Response;
use Slim\Routing\Route;

/**
 * Trait to support unit tests needing route helpers relating to public JWT per-donation authentication.
 * Currently these should all reside in the `...\Donations` namespace and exclude the unauthenticated `Create` action.
 */
trait PublicJWTAuthTrait
{
    private function getRouteWithDonationId(string $method, string $donationId): Route
    {
        $route = new Route(
            [$method],
            '',
            static function () {
                return new Response(200);
            },
            new ResponseFactory(),
            new CallableResolver()
        );
        $route->setArgument('donationId', $donationId);

        return $route;
    }
}
