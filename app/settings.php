<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Monolog\Logger;

return function (ContainerBuilder $containerBuilder) {
    $doctrineConnectionOptions = [];
    if (getenv('APP_ENV') !== 'local') {
        $doctrineConnectionOptions[PDO::MYSQL_ATTR_SSL_CA] = dirname(__DIR__) . '/deploy/rds-ca-2019-root.pem';
    }

    // Global Settings Object
    $containerBuilder->addDefinitions([
        'settings' => [
            'apiClient' => [
                'global' => [
                    'timeout' => getenv('SALESFORCE_CLIENT_TIMEOUT'), // in seconds
                ],
                'campaign' => [
                    'baseUri' => getenv('SALESFORCE_CAMPAIGN_API'),
                ],
                'donation' => [
                    'baseUri' => getenv('SALESFORCE_DONATION_API'),
                ],
                'fund' => [
                    'baseUri' => getenv('SALESFORCE_FUND_API'),
                ],
                'mailer' => [
                    'baseUri' => getenv('MAILER_BASE_URI'),
                    'sendSecret' => getenv('MAILER_SEND_SECRET'),
                ],
                'webhook' => [
                    'baseUri' => getenv('SALESFORCE_WEBHOOK_RECEIVER'),
                ],
            ],

            'appEnv' => getenv('APP_ENV'),

            'displayErrorDetails' => (getenv('APP_ENV') === 'local'),

            'doctrine' => [
                // if true, metadata caching is forcefully disabled
                'dev_mode' => in_array(getenv('APP_ENV'), ['local', 'test'], true),

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
                    'options' => $doctrineConnectionOptions,
                ],
            ],

            'identity' => [
                'baseUri' => getenv('ID_BASE_URI'),
            ],

            'logger' => [
                'name' => 'matchbot',
                'path' => 'php://stdout',
                'level' => getenv('APP_ENV') === 'local' ? Logger::DEBUG : Logger::INFO,
            ],

            'los_rate_limit' => [
                // Dynamic so we can increase it for load tests or as needed based on observed
                // Production behaviour.
                'ip_max_requests'   => (int) (getenv('MAX_CREATES_PER_IP_PER_5M') ?: '1'),
                'ip_reset_time'     => 300, // 5 minutes
                // All non-local envs, including 'test', assume ALB-style forwarded headers will be used.
                'prefer_forwarded' => getenv('APP_ENV') !== 'local',
                'trust_forwarded' => getenv('APP_ENV') !== 'local',
                'forwarded_headers_allowed' => [
                    'X-Forwarded-For',
                ],
                'hash_ips' => true, // Required for Redis storage of IPv6 addresses.
            ],

            'notifier' => [
                'slack' => [
                    'api_token' => getenv('SLACK_API_TOKEN'),
                    // e.g. '#matchbot' – channel for app's own general actions.
                    'channel' => getenv('SLACK_CHANNEL'),
                    // Override channel for administrative Stripe notifications.
                    'stripe_channel' => 'stripe',
                ],
            ],

            'recaptcha' => [
                'secret_key' => getenv('RECAPTCHA_SECRET_KEY'),
            ],

            'redis' => [
                'host' => getenv('REDIS_HOST'),
            ],

            'stripe' => [
                'apiKey' => getenv('STRIPE_SECRET_KEY'),
                'apiVersion' => getenv('STRIPE_API_VERSION'),
                'accountWebhookSecret' => getenv('STRIPE_WEBHOOK_SIGNING_SECRET'),
                'connectAppWebhookSecret' => getenv('STRIPE_CONNECT_WEBHOOK_SIGNING_SECRET'),
            ],
        ],
    ]);
};
