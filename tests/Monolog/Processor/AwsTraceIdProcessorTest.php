<?php

declare(strict_types=1);

namespace MatchBot\Tests\Monolog\Processor;

use DI\Container;
use MatchBot\Monolog\Processor\AwsTraceIdProcessor;
use MatchBot\Tests\TestCase;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

class AwsTraceIdProcessorTest extends TestCase
{
    #[\Override]
    public function setUp(): void
    {
        unset($_SERVER['HTTP_X_AMZN_TRACE_ID']);
    }

    /**
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function testContextAdded(): void
    {
        // https://medium.com/@samrapaport/unit-testing-log-messages-in-laravel-5-6-a2e737247d3a

        $handler = new TestHandler();
        $logger = $this->getRealLoggerWithTestHandler($handler);

        $app = $this->getAppInstance();
        $container = $app->getContainer();
        \assert($container instanceof Container);
        $container->set(LoggerInterface::class, $logger);

        // Because a Monolog Processor doesn't have, and shouldn't need, access to special DI container
        // stuff, the cleanest working way to simulate extra header stuff from Apache seems to be to just
        // overwrite the $_SERVER superglobal key directly.
        $_SERVER['HTTP_X_AMZN_TRACE_ID'] = 'amz-trace-id-123';

        $request = $this->createRequest(
            'GET',
            '/ping',
            '',
            [
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X-Forwarded-For' => '1.2.3.4',
                'HTTP_X_AMZN_TRACE_ID' => 'amz-trace-id-123',
            ],
        );
        $app->handle($request);

        // This warning *already* happens in fully bootstrapped app unit tests, because there is no Redis cache
        // for Doctrine. So we just inspect the handler's record for that log to check the `extra` param is set,
        // rather than simulating a new log within the test.
        $this->assertEquals(
            'Doctrine falling back to array cache - Redis host dummy-redis-hostname',
            $handler->getRecords()[0]['message'],
        );
        $this->assertEquals(300, $handler->getRecords()[0]['level']);
        $this->assertEquals('amz-trace-id-123', $handler->getRecords()[0]['extra']['x-amzn-trace-id']);
    }

    public function testContextNotAdded(): void
    {
        // Ensure no crashes/ logging issues when a request isn't via the ALB, and that no `extra` key shows up
        // for the trace ID.
        $handler = new TestHandler();
        $logger = $this->getRealLoggerWithTestHandler($handler);

        $app = $this->getAppInstance();
        $container = $app->getContainer();
        \assert($container instanceof Container);
        $container->set(LoggerInterface::class, $logger);

        // No non-default headers. No $_SERVER key override.
        $request = $this->createRequest('GET', '/ping');
        $app->handle($request);

        $this->assertEquals(
            'Doctrine falling back to array cache - Redis host dummy-redis-hostname',
            $handler->getRecords()[0]['message'],
        );
        $this->assertEquals(300, $handler->getRecords()[0]['level']);
        $this->assertArrayNotHasKey('x-amzn-trace-id', $handler->getRecords()[0]['extra']);
    }

    protected function getRealLoggerWithTestHandler(HandlerInterface $handler): LoggerInterface
    {
        $logger = new Logger('test-logger');

        $awsTraceIdProcessor = new AwsTraceIdProcessor();
        $logger->pushProcessor($awsTraceIdProcessor);

        // For this test we skip the memory peak & UID processors, since we don't maintain
        // those and expect them to be tested upstream.

        $logger->pushHandler($handler);

        return $logger;
    }
}
