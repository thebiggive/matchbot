<?php

namespace MatchBot\Tests\Application\Matching;

use MatchBot\Application\RealTimeMatchingStorage;

class ArrayMatchingStorage implements RealTimeMatchingStorage
{
    /** @var array<string,string> */
    private array $storage;

    private array $responses = [];

    private bool $multiMode = false;

    /**
     * @var \Closure(string):void|null
     */
    private ?\Closure $preIncrCallback = null;

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
        $this->multiMode = true;
        return $this;
    }

    public function incrBy(string $key, int $increment): string|false|static
    {
        if ($this->preIncrCallback !== null) {
            $callback = $this->preIncrCallback;
            $callback($key);
        }

        $newValue = (float)($this->storage[$key] ?? 0) + $increment;

        $str = "incr $key by $increment, from {$this->storage[$key]} to {$newValue}\n";
        echo $str;

        $this->storage[$key] = (string) $newValue;
        if (! $this->multiMode) {
            return (string)$newValue;
        }

        $this->responses[] = (string) $newValue;

        return $this;
    }

    public function decrBy(string $key, int $decrement): self
    {
        $newValue = (float)($this->storage[$key] ?? 0) - $decrement;
        $str = "decrBy $key by $decrement, from {$this->storage[$key]} to {$newValue}\n";
        echo $str;


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
        if (!in_array('nx', $options, true) || !isset($this->storage[$key])
        ) {
            echo "set $key to $value\n";
            $this->storage[$key] = (string)$value;
        }

        $this->responses[] = true;

        return $this;
    }

    public function exec(): mixed
    {
        $return = $this->responses;
        $this->responses = [];
        $this->multiMode = false;

        return $return;
    }

    /**
     * Sets callback that will be invoked during any call to incrBy
     * to simulate another thread changing what's in the storage.
     *
     * @param \Closure(string):void $callBack
     */
    public function setPreIncrCallBack(\Closure $callBack): void
    {
        $this->preIncrCallback = $callBack;
    }
}
