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
    public function save(Key $key)
    {
        // Do nothing
    }

    /**
     * @return void
     */
    #[\Override]
    public function waitAndSave(Key $key)
    {
        // Do nothing
    }

    /**
     * @return void
     */
    #[\Override]
    public function putOffExpiration(Key $key, float $ttl)
    {
        // Do nothing
    }

    /**
     * @return void
     */
    #[\Override]
    public function delete(Key $key)
    {
        // Do nothing
    }

    #[\Override]
    public function exists(Key $key): bool
    {
        return false;
    }
}
