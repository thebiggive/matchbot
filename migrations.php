<?php

declare(strict_types=1);

return [
    'table_storage' => [
        'table_name' => 'doctrine_migration_versions',
        'version_column_name' => 'version',
        'version_column_length' => 191,
        'executed_at_column_name' => 'executed_at',
        'execution_time_column_name' => 'execution_time',
    ],

    'migrations_paths' => [
        'MatchBot\Migrations' => __DIR__ . '/src/Migrations',
    ],

    // note that although all_or_nothing is useful to make sure migrations don't get left half-done,
    // it also obscures errors by showing a syntax error to do with missing breakpoints instead of the underlying issue.
    // Change to false if necessary in local to debug migration issues.
    'all_or_nothing' => true,
    'check_database_platform' => true,
];
