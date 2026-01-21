<?php

namespace MatchBot\Application;

use Doctrine\DBAL\Schema\MySQLSchemaManager;

/**
 * Based on https://www.liip.ch/en/blog/doctrine-and-generated-columns - makes Doctrine ignore a generated column
 * and associated index that was created manually in an SQL migration instead of via Entity metadata.
 */
class CustomMySQLSchemaManager extends MySQLSchemaManager
{
    private const array GENERATED_INDEXES = [
        ['Campaign', 'FULLTEXT_GLOBAL_SEARCH'],
        ['Campaign', 'FULLTEXT_NAME'],
        ['Campaign', 'FULLTEXT_NORMALISED_NAME'],
        ['Charity', 'FULLTEXT_GLOBAL_SEARCH'],
        ['Charity', 'FULLTEXT_NAME'],
        ['Charity', 'FULLTEXT_NORMALISED_NAME'],
    ];

    private const array GENERATED_COLUMNS = [
        ['Campaign', 'normalisedName'],
        ['Campaign', 'searchable_text'],
        ['Charity', 'normalisedName'],
        ['Charity', 'searchable_text'],
    ];

    #[\Override]
    protected function _getPortableTableIndexesList($tableIndexes, $tableName = null) // phpcs:ignore
    {
        $indexes = parent::_getPortableTableIndexesList($tableIndexes, $tableName);

        return \array_filter(
            $indexes,
            static fn($index) => !in_array([$tableName, $index->getName()], self::GENERATED_INDEXES, true),
        );
    }

    #[\Override]
    protected function _getPortableTableColumnList($table, $database, $tableColumns) // phpcs:ignore
    {
        $columns = parent::_getPortableTableColumnList($table, $database, $tableColumns);

        return array_filter(
            $columns,
            static fn($column) => !in_array([$table, $column->getName()], self::GENERATED_COLUMNS, true),
        );
    }
}
