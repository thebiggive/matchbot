<?php

namespace MatchBot\Application;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\MySQLSchemaManager;

/**
 * Based on https://www.liip.ch/en/blog/doctrine-and-generated-columns - makes Doctrine ignore a generated column
 * and associated index that was created manually in an SQL migration instead of via Entity metadata.
 */
class CustomMySQLSchemaManager extends MySQLSchemaManager
{
//    public function __construct(Connection $connection, AbstractMySQLPlatform $platform)
//    {
//        // Intentionally throw to prove our custom schema manager is being used during validation
////        throw new \Exception('here we go in constructor of custom schema manager');
//         parent::__construct($connection, $platform); // unreachable while we are testing
//    }


    #[\Override]
    protected function _getPortableTableIndexesList($tableIndexes, $tableName = null) // phpcs:ignore
    {
        $indexes = parent::_getPortableTableIndexesList($tableIndexes, $tableName);

        return \array_filter($indexes, function ($index) use ($tableName) {
            return !($index->getName() === 'FULLTEXT_GLOBAL_SEARCH' && $tableName === 'Campaign');
        });
    }

    #[\Override]
    protected function _getPortableTableColumnList($table, $database, $tableColumns) // phpcs:ignore
    {
        $columns = parent::_getPortableTableColumnList($table, $database, $tableColumns);

        return array_filter($columns, function ($column) use ($table) {
            return !($column->getName() === 'searchable_text' && $table === 'Campaign');
        });
    }
}
