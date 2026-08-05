<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use Symfony\Component\Lock\BlockingStoreInterface;
use Symfony\Component\Lock\Key;

/**
 * Dummy Lock Store which always gives an available lock, for unit testing Commands.
 */
class AlwaysAvailableLockStore implements BlockingStoreInterface
{
    /**
     * @return void
     */
    #[\Override]
    public function save(Key $key): void
    {
        // Do nothing
    }

    /**
     * @return void
     */
    #[\Override]
    public function waitAndSave(Key $key): void
    {
        // Do nothing
    }

    /**
     * @return void
     */
    #[\Override]
    public function putOffExpiration(Key $key, float $ttl): void
    {
        // Do nothing
    }

    /**
     * @return void
     */
    #[\Override]
    public function delete(Key $key): void
    {
        // Do nothing
    }

    #[\Override]
    public function exists(Key $key): bool
    {
        return false;
    }
}
