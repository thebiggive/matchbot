<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions;

use DI\Container;
use MatchBot\Client\GenericSalesforceGet;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\StreamFactory;

class SalesforceGetProxyTest extends TestCase
{
    #[\Override]
    public function setUp(): void
    {
        parent::setUp();

        // Always successful generic client for now.
        $clientProphecy = $this->prophesize(GenericSalesforceGet::class);
        $clientProphecy->get(Argument::type('string'))
            ->willReturn(new StreamFactory()->createStream('ok text!'));

        $container = $this->getContainer();
        assert($container instanceof Container);

        $container->set(GenericSalesforceGet::class, $clientProphecy->reveal());
    }

    public function testAllowedPath(): void
    {
        $path = 'funds/services/apexrest/v1.0/funds/slug/we-are-the-champions';
        $request = $this->createRequest('GET', "/sf/$path")
            ->withAttribute('path', $path);

        $app = $this->getAppInstance();
        $response = $app->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('ok text!', $response->getBody()->getContents());
    }

    /**
     * Leaves exception for general HttpErrorHandler.
     */
    public function testUnexpectedPath(): void
    {
        $this->expectException(HttpNotFoundException::class);

        $request = $this->createRequest(
            'GET',
            '/sf/vip',
        );

        $app = $this->getAppInstance();
        $app->handle($request);
    }
}
