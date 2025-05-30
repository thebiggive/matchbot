<?php

namespace MatchBot\Application;

class RedisMatchingStorage implements RealTimeMatchingStorage
{
    public function __construct(private \Redis $redis)
    {
    }

    #[\Override]
    public function get(string $key)
    {
        $return = $this->redis->get($key);
        if ($return instanceof \Redis) {
            return new self($return);
        }

        return $return;
    }

    #[\Override]
    public function set(string $key, string|int $value, array $options): bool|self
    {
        $return = $this->redis->set($key, (string)$value, $options);
        if ($return instanceof \Redis) {
            return new self($return);
        }

        return $return;
    }

    #[\Override]
    public function multi(): self|bool
    {
        $return = $this->redis->multi();
        if (is_bool($return)) {
            return $return;
        }

        return new self($return);
    }

    #[\Override]
    public function incrBy(string $key, int $increment): string|self|false|int
    {
        $return = $this->redis->incrBy($key, $increment);

        if ($return instanceof \Redis) {
            return new self($return);
        }

        return $return;
    }

    #[\Override]
    public function del(string $key): void
    {
        $this->redis->del($key);
    }

    /**
     * @return array<mixed>|false|self
     */
    #[\Override]
    public function exec(): self|array|false
    {
        $return = $this->redis->exec();
        if ($return instanceof \Redis) {
            return new self($return);
        }

        return $return;
    }

    #[\Override]
    public function decrBy(string $key, int $decrement)
    {
        $return = $this->redis->decr($key, $decrement);

        if ($return instanceof \Redis) {
            return new self($return);
        }

        return $return;
    }
}
