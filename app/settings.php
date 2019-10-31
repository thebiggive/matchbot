<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Monolog\Logger;

return function (ContainerBuilder $containerBuilder) {
    // Global Settings Object
    $containerBuilder->addDefinitions([
        'settings' => [
            'displayErrorDetails' => (getenv('APPLICATION_ENV') === 'local'),

            'doctrine' => [
                // if true, metadata caching is forcefully disabled
                'dev_mode' => (getenv('APPLICATION_ENV') === 'local'),

                'cache_dir' => __DIR__ . '/../var/doctrine',
                'metadata_dirs' => [__DIR__ . '/../src/Domain'],

                'connection' => [
                    'driver' => 'pdo_mysql',
                    'host' => getenv('MYSQL_HOST'),
                    'port' => 3306,
                    'dbname' => getenv('MYSQL_SCHEMA'),
                    'user' => getenv('MYSQL_USER'),
                    'password' => getenv('MYSQL_PASSWORD'),
                    'charset' => 'utf8mb4',
                    'default_table_options' => [
                        'collate' => 'utf8mb4_unicode_ci',
                    ],
                ],
            ],

            'logger' => [
                'name' => 'matchbot',
                'path' => 'php://stdout',
                'level' => Logger::DEBUG,
            ],

            'apiClient' => [
                'campaign' => [
                    'baseUri' => getenv('SALESFORCE_CAMPAIGN_API'),
                ],
                'fund' => [
                    'baseUri' => getenv('SALESFORCE_FUND_API'),
                ],
            ],
        ],
    ]);
};
