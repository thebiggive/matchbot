<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Auth;

use GuzzleHttp\Psr7\Utils;
use MatchBot\Application\Auth\DonationRecaptchaMiddleware;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use ReCaptcha\ReCaptcha;
use ReCaptcha\Response as ReCaptchaResponse;
use Slim\App;
use Slim\CallableResolver;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use Slim\Psr7\Uri;
use Slim\Routing\Route;
use Slim\Exception\HttpUnauthorizedException;
use Symfony\Component\Serializer\SerializerInterface;

class DonationRecaptchaMiddlewareTest extends TestCase
{
    use DonationTestDataTrait;

    public function testFailure(): void
    {
        $donationObject = $this->getTestDonation();
        $donation = $donationObject->toApiModel();
        $donation['creationRecaptchaCode'] = 'bad response';
        $body = json_encode($donation);

        $request = $this->createRequest('POST', '/v1/donations', $body);

        $this->expectException(HttpUnauthorizedException::class);
        $this->expectExceptionMessage('Unauthorised');

        // Because the 401 ends the request, we can dispatch this against realistic, full app
        // middleware and test this piece of middleware in the process.
        $response = $this->getAppInstance(false)
            ->getMiddlewareDispatcher()
            ->handle($request);
    }

    public function testSuccess(): void
    {
        $donationObject = $this->getTestDonation();
        $donation = $donationObject->toApiModel();
        $donation['creationRecaptchaCode'] = 'good response';
        $body = json_encode($donation);

        $request = $this->createRequest('POST', '/v1/donations', $body)
            // Because we're only running the single middleware and not the app stack, we need
            // to set this attribute manually to simulate what ClientIp middleware does on real
            // runs.
            ->withAttribute('client-ip', '1.2.3.4');

        /** @var App $app */
        $container = $this->getAppInstance(false)->getContainer();

        // For the success case we can't fully handle the request without covering a lot of stuff
        // outside the middleware, since that would mean creating a donation and so mocking DB bits
        // etc. So unlike for failure, we create an isolated middleware object to invoke.

        $middleware = new DonationRecaptchaMiddleware(
            $container->get(LoggerInterface::class), // null logger already set up
            $container->get(ReCaptcha::class), // already mocked with success simulation
            $container->get(SerializerInterface::class),
        );
        $response = $middleware->process($request, $this->getSuccessHandler());

        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Simulate a route returning a 200 OK. Test methods should get here only when they expect auth
     * success from the middleware.
     */
    private function getSuccessHandler(): Route
    {
        return new Route(
            ['POST'],
            '/v1/donations',
            static function () {
                return new Response(200);
            },
            new ResponseFactory(),
            new CallableResolver()
        );
    }
}
