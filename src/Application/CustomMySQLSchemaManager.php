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
    protected function _getPortableTableIndexesList(array $tableIndexes, ?string $tableName = null): array // phpcs:ignore
    {
        $indexes = parent::_getPortableTableIndexesList($tableIndexes, $tableName);

        return \array_filter(
            $indexes,
            static fn($index) => !in_array(
                needle: [$tableName, $index->getObjectName()->toString()],
                haystack: self::GENERATED_INDEXES,
                strict: true
            ),
        );
    }

    #[\Override]
    protected function _getPortableTableColumnList(string $table, string $database, array $tableColumns): array // phpcs:ignore
    {
        $columns = parent::_getPortableTableColumnList($table, $database, $tableColumns);

        return array_filter(
            $columns,
            static fn($column) => !in_array(
                needle: [$table, $column->getObjectName()->toString()], // @phpstan.ignore method.internalClass
                // releasedAt is new, want to wait until its in prod DB before we allow the ORM to rely on it.
                haystack: self::GENERATED_COLUMNS,
                strict: true,
            ),
        );
    }
}
