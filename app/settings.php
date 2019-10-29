<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Monolog\Logger;

return function (ContainerBuilder $containerBuilder) {
    // Global Settings Object
    $containerBuilder->addDefinitions([
        'settings' => [
            'displayErrorDetails' => (getenv('APPLICATION_ENV') === 'local'),

            // TODO can we remove native DB config & PDO DI service?
            'db' => [
                'host' => getenv('MYSQL_HOST'),
                'dbname' => getenv('MYSQL_SCHEMA'),
                'user' => getenv('MYSQL_USER'),
                'pass' => getenv('MYSQL_PASSWORD'),
            ],

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
                'path' => isset($_ENV['docker']) ? 'php://stdout' : __DIR__ . '/../logs/app.log',
                'level' => Logger::DEBUG,
            ],
        ],
    ]);
};
