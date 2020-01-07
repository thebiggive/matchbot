<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions;

use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Tests\TestCase;

class StatusTest extends TestCase
{
    public function testSuccess(): void
    {
        $app = $this->getAppInstance();

        $request = $this->createRequest('GET', '/ping');
        $response = $app->handle($request);
        $payload = (string) $response->getBody();

        $expectedPayload = new ActionPayload(200, ['status' => 'OK']);
        $serializedPayload = json_encode($expectedPayload, JSON_PRETTY_PRINT);

        $this->assertEquals($serializedPayload, $payload);
    }
}
