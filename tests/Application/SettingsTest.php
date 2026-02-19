<?php

namespace MatchBot\Tests\Application;

use MatchBot\Application\Settings;
use PHPUnit\Framework\TestCase;

class SettingsTest extends TestCase
{
    public const array MINIMAL_REQUIRED_VARS = [
        'APP_ENV' => 'x',
        'MYSQL_HOST' => '',
        'MYSQL_SCHEMA' => '',
        'MYSQL_USER' => '',
        'MYSQL_PASSWORD' => '',
        'REDIS_HOST' => 'x',
    ];

    public function testItInstantiatesWithMinmialRequiredEnvironmentSettings(): void
    {
        $settings = Settings::fromEnvVars(
            self::MINIMAL_REQUIRED_VARS
        );

        $this->assertSame('x', $settings->appEnv);
        $this->assertFalse($settings->enableNoReservationsMode);
    }

    public function testItEnablesNoReservationsModeWhenRequested(): void
    {
        $settings = Settings::fromEnvVars(self::MINIMAL_REQUIRED_VARS + ['ENABLE_NO_RESERVATIONS_MODE' => 'true']);

        $this->assertTrue($settings->enableNoReservationsMode);
    }

    public function testItDoesNotEnablesNoReservationsModeWhenNotRequested(): void
    {
        $settings = Settings::fromEnvVars(self::MINIMAL_REQUIRED_VARS + ['ENABLE_NO_RESERVATIONS_MODE' => 'truly not what we want']);

        $this->assertFalse($settings->enableNoReservationsMode);
    }
}
