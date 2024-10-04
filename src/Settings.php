<?php

declare(strict_types=1);

namespace MatchBot;
use DI\ContainerBuilder;
use Monolog\Logger;

/**
 * @psalm-type matchbotAppSettings = array{
 *     apiClient: array{
 *         campaign: array{baseUri: string},
 *         donation: array{baseUri: string},
 *         fund: array{baseUri: string},
 *         global: array{timeout: string},
 *         mailer: array{
 *             baseUri: string,
 *             sendSecret: string
 *         }
 *     },
 *     appEnv: string,
 *     displayErrorDetails: bool,
 *     doctrine: array{
 *      cache_dir: string,
 *      connection: array{
 *          charset: 'utf8mb4',
 *          dbname: string,
 *          defaultTableOptions: array{collate: 'utf8mb4_unicode_ci'},
 *          driver: 'pdo_mysql',
 *          driverOptions: array{1009: string},
 *          host: string,
 *          password: string,
 *          port: 3306,
 *          user: string
 *      },
 *      dev_mode: bool,
 *      metadata_dirs: list<string>
 *     },
 *     identity: array{baseUri: string},
 *     logger: array{level: 100|200, name: 'matchbot', path: string},
 *     los_rate_limit: array{
 *      forwarded_headers_allowed: list<string>,
 *      hash_ips: true,
 *      ip_max_requests: int,
 *      ip_reset_time: 300,
 *      prefer_forwarded: bool,
 *      trust_forwarded: bool
 *      },
 *     notifier: array{slack: array{api_token: string, channel: string, stripe_channel: 'stripe'}},
 *     redis: array{host: string}, salesforce: array{apiKey: non-empty-string},
 *     stripe: array{accountWebhookSecret: string, apiKey: string, connectAppWebhookSecret: string}
 * }
 */
class Settings
{
    /**
     * @param array{1009?: string} $doctrineConnectionOptions
     * @psalm-return matchbotAppSettings
     */
    private static function getMatchbotAppSettings(array $doctrineConnectionOptions): array
    {
        $max_creates_per_ip_per_5M_ENV_VAR = getenv('MAX_CREATES_PER_IP_PER_5M');
        $max_creates_per_ip_5M =
            $max_creates_per_ip_per_5M_ENV_VAR !== false ? (int)($max_creates_per_ip_per_5M_ENV_VAR) : 1;
        return [
            'apiClient' => [
                'global' => [
                    'timeout' => self::requireEnvVar('SALESFORCE_CLIENT_TIMEOUT'), // in seconds
                ],
                'campaign' => [
                    'baseUri' => self::requireEnvVar('SALESFORCE_CAMPAIGN_API'),
                ],
                'donation' => [
                    'baseUri' => self::requireEnvVar('SALESFORCE_DONATION_API'),
                ],
                'fund' => [
                    'baseUri' => self::requireEnvVar('SALESFORCE_FUND_API'),
                ],
                'mailer' => [
                    'baseUri' => self::requireEnvVar('MAILER_BASE_URI'),
                    'sendSecret' => self::requireEnvVar('MAILER_SEND_SECRET'),
                ],
            ],
            'appEnv' => self::requireEnvVar('APP_ENV'),
            'displayErrorDetails' => (getenv('APP_ENV') === 'local'),
            'doctrine' => [
                // if true, metadata caching is forcefully disabled
                'dev_mode' => in_array(getenv('APP_ENV'), ['local', 'test'], true),
                'cache_dir' => __DIR__ . '/../var/doctrine',
                'metadata_dirs' => [__DIR__ . '/../src/Domain'],
                'connection' => [
                    'driver' => 'pdo_mysql',
                    'host' => self::requireEnvVar('MYSQL_HOST'),
                    'port' => 3306,
                    'dbname' => self::requireEnvVar('MYSQL_SCHEMA'),
                    'user' => self::requireEnvVar('MYSQL_USER'),
                    'password' => self::requireEnvVar('MYSQL_PASSWORD'),
                    'charset' => 'utf8mb4',
                    'defaultTableOptions' => [
                        'collate' => 'utf8mb4_unicode_ci',
                    ],
                    'driverOptions' => $doctrineConnectionOptions,
                ],
            ],

            'identity' => [
                'baseUri' => self::requireEnvVar('ID_BASE_URI'),
            ],

            'logger' => [
                'name' => 'matchbot',
                'path' => 'php://stdout',
                'level' => getenv('APP_ENV') === 'local' ? Logger::DEBUG : Logger::INFO,
            ],

            'los_rate_limit' => [
                // Dynamic so we can increase it for load tests or as needed based on observed
                // Production behaviour.
                'ip_max_requests' => $max_creates_per_ip_5M,
                'ip_reset_time' => 300, // 5 minutes
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

            'redis' => [
                'host' => self::requireEnvVar('REDIS_HOST'),
            ],

            'stripe' => [
                'apiKey' => ('STRIPE_SECRET_KEY'),
                'accountWebhookSecret' => ('STRIPE_WEBHOOK_SIGNING_SECRET'),
                'connectAppWebhookSecret' => ('STRIPE_CONNECT_WEBHOOK_SIGNING_SECRET'),
            ],

            'salesforce' => [
                // authenticates requests originating from salesforce to matchbot:
                'apiKey' => self::requireEnvVar('SALESFORCE_SECRET_KEY'),
            ]
        ];
    }

    public static function applyTo(ContainerBuilder $containerBuilder): void
    {
        $doctrineConnectionOptions = [];
        if (!in_array(getenv('APP_ENV'), ['local', 'test'])) {
            $doctrineConnectionOptions[\PDO::MYSQL_ATTR_SSL_CA] =
                dirname(__DIR__) . '/deploy/rds-ca-eu-west-1-bundle.pem';
        }

        $containerBuilder->addDefinitions([
            'settings' => self::getMatchbotAppSettings($doctrineConnectionOptions),
        ]);
    }

    private static function requireEnvVar(string $varName): string
    {
        $value = getenv($varName);
        if (! is_string($value)) {
            throw new \Exception("Required env param $varName does not have a string value, must be set");
        }

        return $value;
    }
}

