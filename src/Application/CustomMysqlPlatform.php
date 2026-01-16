<?php

namespace MatchBot\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\MySQLSchemaManager;

class CustomMysqlPlatform extends \Doctrine\DBAL\Platforms\MySQL84Platform
{
    #[\Override]
    public function createSchemaManager(Connection $connection): MySQLSchemaManager
    {
        return new CustomMySQLSchemaManager($connection, $this);
    }
}
