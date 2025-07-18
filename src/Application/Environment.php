<?php

namespace MatchBot\Application;

enum Environment
{
    case Production;
    case Staging;
    case Regression;
    case Local;
    case Test;

    public static function current(): self
    {
        $env = getenv('APP_ENV');
        if ($env === false) {
            throw new \RuntimeException('APP_ENV environment variable required');
        }

        return self::fromAppEnv($env);
    }

    public static function fromAppEnv(string $name): self
    {
        return match ($name) {
            'production' => self::Production,
            'staging' => self::Staging,
            'regression' => self::Regression,
            'local' => self::Local,
            'test' => self::Test,
            default => throw new \Exception("Unknown environment \"$name\""),
        };
    }

    public function toLower(): string
    {
        return strtolower($this->name);
    }

    public function isProduction(): bool
    {
        return match ($this) {
            // listing all cases in case we ever have multiple defined production envs.
            self::Production => true,
            self::Regression => false,
            self::Staging => false,
            self::Local => false,
            self::Test => false
        };
    }

    public function isFeatureEnabledRegularGiving(): bool
    {
        return true;
    }

    public function isLocal(): bool
    {
        return $this === self::Local;
    }
}
