<?php

namespace MatchBot\Application;

enum Environment
{
    case Production;
    case Staging;
    case Regression;
    case Local;

    public function isFeatureEnabledListPastDonations(): bool
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
            default => throw new \Exception("Unknown environment \"$name\""),
        };
    }
}
