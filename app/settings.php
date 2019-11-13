<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Monolog\Logger;

return function (ContainerBuilder $containerBuilder) {
    // Global Settings Object
    $containerBuilder->addDefinitions([
        'settings' => [
            'apiClient' => [
                'campaign' => [
                    'baseUri' => getenv('SALESFORCE_CAMPAIGN_API'),
                ],
                'donation' => [
                    'baseUri' => getenv('SALESFORCE_DONATION_API'),
                ],
                'fund' => [
                    'baseUri' => getenv('SALESFORCE_FUND_API'),
                ],
                'webhook' => [
                    'baseUri' => getenv('SALESFORCE_WEBHOOK_RECEIVER'),
                ],
            ],

            'appEnv' => getenv('APP_ENV'),

            'displayErrorDetails' => (getenv('APP_ENV') === 'local'),

            'doctrine' => [
                // if true, metadata caching is forcefully disabled
                'dev_mode' => (getenv('APP_ENV') === 'local'),

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

            'redis' => [
                'host' => getenv('REDIS_HOST'),
            ]
        ],
    ]);
};
