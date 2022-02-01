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
                    'options' => $doctrineConnectionOptions,
                ],
            ],

            'enthuse' => [
                'fee' => [
                    'fixed' => [
                        'default' => '0.2',               // Baseline fee in pounds.
                    ],
                    'gift_aid_percentage' => '1',   // As a propotion of the *total* donation
                                                    // if Gift Aid claimed, i.e. 1/4 of Gift Aid fee %.
                    'main_percentage_standard' => '1.9',
                ],
            ],

            'gift_aid' => [
                'pilot_salesforce_ids' => empty(getenv('GIFT_AID_PILOT_CHARITIES_SALESFORCE_IDS'))
                    ? null
                    : json_decode(
                        getenv('GIFT_AID_PILOT_CHARITIES_SALESFORCE_IDS'),
                        true,
                        512,
                        JSON_THROW_ON_ERROR,
                    ),
            ],

            'logger' => [
                'name' => 'matchbot',
                'path' => 'php://stdout',
                'level' => Logger::DEBUG,
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
                'fee' => [
                    // Based on Stripe support email 9/4/21.
                    'fixed' => [
                        'CHF' => '0.3',
                        'DKK' => '1.8',
                        'EUR' => '0.25',
                        'GBP' => '0.2', // Baseline fee in pounds
                        'NOK' => '1.8',
                        'SEK' => '1.8',
                        'USD' => '0.3',
                        'default' => '0.2',
                    ],
                    'gift_aid_percentage' => '0.75', // 3% of Gift Aid amount.
                    'main_percentage_standard' => '1.5',
                    'main_percentage_amex_or_non_uk_eu' => '3.2',
                    // The rate at which VAT is either being or is about to be charged.
                    'vat_percentage_live' => getenv('VAT_PERCENTAGE_LIVE'),
                    // The rate at which VAT is being charged if before the switch date.
                    'vat_percentage_old' => getenv('VAT_PERCENTAGE_OLD'),
                    // DateTime constructor-ready string: when the live VAT rate replaces the old one.
                    'vat_live_date' => getenv('VAT_LIVE_DATE'),
                ],
            ],
        ],
    ]);
};
