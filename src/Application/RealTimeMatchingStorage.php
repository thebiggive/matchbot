<?php

namespace MatchBot\Application;

interface RealTimeMatchingStorage
{
    public function set(string $key, string|int $value, array $options): bool|self;

    /**
     * @return  string|false|self $key
     */
    public function get(string $key);

    public function multi(): self|bool;

    /**
     * @return string|false|self|int $key
     */
    public function incrBy(string $key, int $increment);

    /**
     * @return string|false|self|int $key
     */
    public function decrBy(string $key, int $decrement);

    public function del(string $key): void;

    /**
     * @return array|false|self
     */
    public function exec();
}