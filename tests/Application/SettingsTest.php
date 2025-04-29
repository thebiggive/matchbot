<?php

namespace Application;

use MatchBot\Application\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    public function testItInstantiatesWithMinmialRequiredEnvironmentSettings(): void
    {
        $settings = Settings::fromEnvVars(
            [
                'APP_ENV' => 'x',
                'MYSQL_HOST' => '',
                'MYSQL_SCHEMA' => '',
                'MYSQL_USER' => '',
                'MYSQL_PASSWORD' => '',
                'REDIS_HOST' => 'x',
            ]
        );

        $this->assertSame('x', $settings->appEnv);
    }
}
