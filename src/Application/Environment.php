<?php

namespace MatchBot\Application;

enum Environment
{
    case Production;
    case Staging;
    case Regression;
    case Local;
    case Test;

    public function isFeatureEnabledListPastDonations(): bool
    {
        return $this !== self::Production;
    }

    public function isFeatureEnabledRegularGiving(): bool
    {
        return $this !== self::Production;
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
}
