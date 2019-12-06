<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Commands;

use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * Dummy Lock Store which always gives an available lock, for unit testing Commands.
 */
class AlwaysAvailableLockStore implements PersistingStoreInterface
{
    public function save(Key $key)
    {
        // Do nothing
    }

    public function putOffExpiration(Key $key, $ttl)
    {
        // Do nothing
    }

    public function delete(Key $key)
    {
        // Do nothing
    }

    public function exists(Key $key)
    {
        return false;
    }
}
