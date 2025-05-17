<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Auth;

use MatchBot\Application\Auth\SalesforceAuthMiddleware;
use MatchBot\Tests\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\NullLogger;
use Slim\CallableResolver;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Response;
use Slim\Routing\Route;

/**
 * Based on \Mailer\Tests\Application\Auth\SendAuthMiddlewareTest
 */
class SalesforceAuthMiddlewareTest extends TestCase
{
    private const string API_KEY = 'TEST-API-KEY';

    public function testMissingAuthRejected(): void
    {
        $body = bin2hex(random_bytes(100));
        $request = $this->buildRequest($body, null);

        $response = $this->getInstance()->process($request, $this->getSuccessHandler());

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testWrongAuthRejected(): void
    {
        $body = bin2hex(random_bytes(100));
        $hash = hash_hmac('sha256', $body, 'the-wrong-secret');
        $request = $this->buildRequest($body, $hash);

        $response = $this->getInstance()->process($request, $this->getSuccessHandler());

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testCorrectAuthAccepted(): void
    {
        $body = bin2hex(random_bytes(100));
        $hash = hash_hmac('sha256', $body, self::API_KEY);

        $request = $this->buildRequest($body, $hash);
        $response = $this->getInstance()->process($request, $this->getSuccessHandler());

        $this->assertEquals(200, $response->getStatusCode());
    }

    private function buildRequest(string $body, ?string $hash = null): ServerRequestInterface
    {
        $headers = $hash !== null ? ['x-send-verify-hash' => $hash] : [];


        return $this->createRequest('GET', 'any-path', $body, $headers);
    }

    /**
     * Simulate a route returning a 200 OK. Test methods should get here only when they expect auth
     * success from the middleware.
     *
     * @return Route<null>
     */
    private function getSuccessHandler(): Route
    {
        return new Route(
            ['get'],
            '',
            static function () {
                return new Response(200);
            },
            new ResponseFactory(),
            new CallableResolver()
        );
    }

    private function getInstance(): SalesforceAuthMiddleware
    {
        return new SalesforceAuthMiddleware(self::API_KEY, new NullLogger());
    }
}
