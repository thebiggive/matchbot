<?php

use GuzzleHttp\Psr7\ServerRequest;
use MatchBot\IntegrationTests\IntegrationTest;

class PingSmokeTest extends IntegrationTest
{
    public function testItReturnsOKStatus(): void
    {
        $request = new ServerRequest('GET', '/ping');

        $response = $this->getApp()->handle($request);

        $this->assertJsonStringEqualsJsonString(
            '{"status": "OK"}',
            (string) $response->getBody()
        );
    }
}
