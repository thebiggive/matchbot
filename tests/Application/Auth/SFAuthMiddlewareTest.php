<?php

namespace MatchBot\Tests\Application\Auth;

use Laminas\Diactoros\Response;
use Laminas\Diactoros\ServerRequest;
use MatchBot\Application\Auth\SFAuthMiddleware;
use MatchBot\Tests\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Interfaces\DispatcherInterface;
use Slim\Routing\Route;
use Slim\Routing\RouteCollector;
use Slim\Routing\RouteContext;
use Slim\Routing\RouteParser;
use Slim\Routing\RoutingResults;

class SFAuthMiddlewareTest extends TestCase implements RequestHandlerInterface
{
    private bool $handled = false;

    /**
     * @dataProvider scenarios
     */
    public function testItAcceptsValidTokens(int $clockOffset, string $secret, bool $shouldAccept): void
    {
        $now = new \DateTimeImmutable('2026-02-10T12:00:00')
            ->add(\DateInterval::createFromDateString("$clockOffset minutes"));

        $middleware = new SFAuthMiddleware($secret, $now, new NullLogger());

        $routeProphecy = $this->prophesize(Route::class);
        $routeProphecy->getArgument('donationId')->willReturn('3da607ee-1661-11f1-b9d1-fc5cee98dc66');

        $route = $routeProphecy->reveal();

        $routeParser = new RouteParser($this->createStub(RouteCollector::class));
        $routingResults = new RoutingResults(
            $this->createStub(DispatcherInterface::class),
            'GET',
            'placeholder-uri',
            1
        );

        $request = new ServerRequest()
            ->withAttribute(RouteContext::ROUTE, $route)
            ->withAttribute(RouteContext::ROUTE_PARSER, $routeParser)
            ->withAttribute(RouteContext::ROUTING_RESULTS, $routingResults)
            ->withQueryParams(['t' => '519d7178074df5a5d5cd0331fdcd0153f9b122de9ae285c644d372f85d0c3165'])
        ;

        try {
            $middleware->process($request, $this);
        } catch (HttpUnauthorizedException) {
            // no-op.
        }

        $this->assertSame($shouldAccept, $this->handled);
    }

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $this->handled = true;

        return new Response();
    }

    /**
     * @return list<array{0: int, 1: string, 2: bool}>
     */
    public function scenarios(): array
    {
        // time offset, configured secret on matchbot side, expected authorization.

        return [
            [-2, 'TOPSECRET', false],
            [-1, 'TOPSECRET', true],
            [0, 'TOPSECRET', true],
            [0, 'WRONGSECRET', false],
            [1, 'TOPSECRET', true],
            [2, 'TOPSECRET', false],
        ];
    }
}
