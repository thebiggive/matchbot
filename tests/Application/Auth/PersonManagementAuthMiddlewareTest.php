<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Auth;

use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use MatchBot\Tests\Application\Actions\Donations\CreateTest;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Slim\CallableResolver;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Response;
use Slim\Routing\Route;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Routing\RouteContext;
use Slim\Routing\RouteParser;
use Slim\Routing\RoutingResults;

/**
 * Success paths currently tested as part of full Action tests in {@see CreateTest}.
 */
class PersonManagementAuthMiddlewareTest extends TestCase
{
    use DonationTestDataTrait;

    public function testNoRouteInContext(): void
    {
        $this->expectException(HttpUnauthorizedException::class);
        $this->expectExceptionMessage('Unauthorised');

        // We need to mock some routing innards to simulate a missing route, as RouteContext is `final`.
        $routingResultsProphecy = $this->prophesize(RoutingResults::class);
        $routeParserProphecy = $this->prophesize(RouteParser::class);

        $request = $this->createRequest('POST', '/v999/bad-route')
            ->withAttribute(RouteContext::ROUTING_RESULTS, $routingResultsProphecy->reveal())
            ->withAttribute(RouteContext::ROUTE_PARSER, $routeParserProphecy->reveal());

        // We want to test how the middleware acts against a hypothetical misuse but without
        // including a broken route in our live config. So rather than do a real dispatch we want
        // to simulate routing middleware setup – so that the Request can evaluate routes but finds that
        // there are none. The middleware's success should depend upon a person ID being verified
        // inside the route's path.
        // It's simplest to directly invoke the middleware because a whole app run would require
        // re-configuration (modifying the test `getAppInstance()` substantially) in order to set
        // up diverging test routes and not just throw `HttpNotFoundException`.
        $middleware = $this->getAppInstance()->getContainer()->get(PersonManagementAuthMiddleware::class);
        $middleware->process($request, $this->getSuccessHandler());
    }

    public function testNoJwt(): void
    {
        $this->expectException(HttpUnauthorizedException::class);
        $this->expectExceptionMessage('Unauthorised');

        $donationObject = $this->getTestDonation();
        $donation = $donationObject->toApiModel();
        $donation['personId'] = 'cus_aaaaaaaaaaaa11';
        $body = json_encode($donation);

        $request = $this->createRequest('POST', '/v2/people/12345678-1234-1234-1234-1234567890ab/donations', $body);

        // Because the error ends the request, we can dispatch this against realistic, full app
        // middleware and test this piece of middleware in the process.
        $this->getAppInstance(false)
            ->getMiddlewareDispatcher()
            ->handle($request);
    }

    /**
     * Simulate a route returning a 200 OK.
     */
    private function getSuccessHandler(): Route
    {
        return new Route(
            ['POST'],
            '/v2/people/12345678-1234-1234-1234-1234567890ab/donations',
            static function () {
                return new Response(200);
            },
            new ResponseFactory(),
            new CallableResolver()
        );
    }
}
