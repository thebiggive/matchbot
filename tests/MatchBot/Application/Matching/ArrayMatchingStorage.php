<?php

namespace MatchBot\Tests\Application\Matching;

use MatchBot\Application\RealTimeMatchingStorage;

class ArrayMatchingStorage implements RealTimeMatchingStorage
{
    /** @var array<string,string> */
    private array $storage;

    private array $responses = [];

    public function __construct()
    {
        $this->storage = [];
    }

    public function get(string $key): string|false
    {
        return $this->storage[$key] ?? false;
    }

    public function multi(): static
    {
        return $this;
    }

    public function incrBy(string $key, int $increment): string|false|static
    {
        $newValue = $this->storage[$key] ?? 0 + $increment;

        $this->storage[$key] = (string) $newValue;
        $this->responses[] = (string) $newValue;

        return $this;
    }

    public function decrBy(string $key, int $decrement): self
    {
        $newValue = $this->storage[$key] ?? 0 - $decrement;

        $this->storage[$key] = (string) $newValue;
        $this->responses[] = (string) $newValue;
        return $this;
    }

    public function del(string $key): void
    {
        unset($this->storage[$key]);
    }

    public function set(string $key, string|int $value, array $options): bool|static
    {
        $this->storage[$key] = (string)$value;
        $this->responses[] = true;

        return $this;
    }

    public function exec(): mixed
    {
        $return = $this->responses;
        $this->responses = [];

        return $return;
    }
}