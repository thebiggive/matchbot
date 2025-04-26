<?php

namespace MatchBot\Application;

use Doctrine\DBAL\DriverManager as DoctrineDiverManager;
use Monolog\Logger;
use PDO;

/**
 * @readonly
 * @psalm-import-type Params from DoctrineDiverManager
 *
 * @psalm-type ApiClient array{global: array{timeout: string}, salesforce: array{baseUri: string}, mailer: array{baseUri: string, sendSecret: string }}
 */
class Settings
{
    /** @var ApiClient */
    public array $apiClient;
    public string $appEnv;
    public bool $displayErrorDetails;

    /** @var array{
     *     dev_mode: boolean,
     *     connection: Params,
     *     metadata_dirs: array<string>,
     *     cache_dir: string,
     *     } */
    public array $doctrine;

    /** @var array{baseUri: string} */
    public array $donate;

    /** @var array{baseUri: string} */
    public array $identity;

    /** @var array{name: 'matchbot', path: "php://stdout", level: int} */
    public array $logger;

    /**
     * @var array
     */
    public array $los_rate_limit;

    /**
     * @var array{slack: array{api_token: string, channel: string, stripe_channel: 'stripe'}}
     */
    public array $notifier;

    /** @var array{host: non-empty-string} */
    public array $redis;

    /** @var array{apiKey: non-empty-string} */
    public array $salesforce;

    /** @var array{apiKey: non-empty-string, accountWebhookSecret: string, connectAppWebhookSecret: string} */
    public array $stripe;

    private function __construct()
    {
        $doctrineConnectionOptions = [];
        $appEnv = $this->getNonEmptyStringEnv('APP_ENV');

        $this->appEnv = $appEnv;
        if (!in_array($appEnv, ['local', 'test'])) {
            $doctrineConnectionOptions[PDO::MYSQL_ATTR_SSL_CA] =
                dirname(__DIR__) . '/deploy/rds-ca-eu-west-1-bundle.pem';
        }

        $this->apiClient = [
            'global' => [
                'timeout' => $this->getStringEnv('SALESFORCE_CLIENT_TIMEOUT'), // in seconds
            ],
            'salesforce' => [
                'baseUri' => $this->getStringEnv('SALESFORCE_API_BASE'),
            ],
            'mailer' => [
                'baseUri' => $this->getStringEnv('MAILER_BASE_URI'),
                'sendSecret' => $this->getStringEnv('MAILER_SEND_SECRET'),
            ],
        ];

        $this->displayErrorDetails = ($appEnv === 'local');

        $this->doctrine = [
            // if true, metadata caching is forcefully disabled
            'dev_mode' => in_array($appEnv, ['local', 'test'], true),

            'cache_dir' => __DIR__ . '/../var/doctrine',
            'metadata_dirs' => [__DIR__ . '/../src/Domain'],

            'connection' => [
                'driver' => 'pdo_mysql',
                'host' => $this->getStringEnv('MYSQL_HOST'),
                'port' => 3306,
                'dbname' => $this->getStringEnv('MYSQL_SCHEMA'),
                'user' => $this->getStringEnv('MYSQL_USER'),
                'password' => $this->getStringEnv('MYSQL_PASSWORD'),
                'charset' => 'utf8mb4',
                'defaultTableOptions' => ['collate' => 'utf8mb4_unicode_ci'],
                'driverOptions' => $doctrineConnectionOptions,
            ],
        ];

        $this->donate = [
            'baseUri' => $this->getStringEnv('ACCOUNT_MANAGEMENT_BASE_URI'),
        ];

        $this->identity = [
            'baseUri' => $this->getStringEnv('ID_BASE_URI'),
        ];

        $this->logger = [
            'name' => 'matchbot',
            'path' => 'php://stdout',
            'level' => $appEnv === 'local' ? Logger::DEBUG : Logger::INFO,
        ];

        $maxCreatesPerIpPerSM = getenv('MAX_CREATES_PER_IP_PER_5M');
        if (is_string($maxCreatesPerIpPerSM)) {
            $this->los_rate_limit = [
                // Dynamic so we can increase it for load tests or as needed based on observed
                // Production behaviour.
                'ip_max_requests' => (int)($maxCreatesPerIpPerSM),
                'ip_reset_time' => 300, // 5 minutes
                // All non-local envs, including 'test', assume ALB-style forwarded headers will be used.
                'prefer_forwarded' => $appEnv !== 'local',
                'trust_forwarded' => $appEnv !== 'local',
                'forwarded_headers_allowed' => [
                    'X-Forwarded-For',
                ],
                'hash_ips' => true, // Required for Redis storage of IPv6 addresses.
            ];
        } else {
            $this->los_rate_limit = [
                // Dynamic so we can increase it for load tests or as needed based on observed
                // Production behaviour.
                'ip_max_requests' => (int)('1'),
                'ip_reset_time' => 300, // 5 minutes
                // All non-local envs, including 'test', assume ALB-style forwarded headers will be used.
                'prefer_forwarded' => $appEnv !== 'local',
                'trust_forwarded' => $appEnv !== 'local',
                'forwarded_headers_allowed' => [
                    'X-Forwarded-For',
                ],
                'hash_ips' => true, // Required for Redis storage of IPv6 addresses.
            ];
        }

        $this->notifier = [
            'slack' => [
                'api_token' => $this->getStringEnv('SLACK_API_TOKEN'),
                // e.g. '#matchbot' – channel for app's own general actions.
                'channel' => $this->getStringEnv('SLACK_CHANNEL'),
                // Override channel for administrative Stripe notifications.
                'stripe_channel' => 'stripe',
            ],
        ];

        $this->redis = [
            'host' => $this->getNonEmptyStringEnv('REDIS_HOST'),
        ];

        $this->stripe = [
            'apiKey' => $this->getNonEmptyStringEnv('STRIPE_SECRET_KEY'),
            'accountWebhookSecret' => $this->getStringEnv('STRIPE_WEBHOOK_SIGNING_SECRET'),
            'connectAppWebhookSecret' => $this->getStringEnv('STRIPE_CONNECT_WEBHOOK_SIGNING_SECRET'),
        ];

        $this->salesforce = [
            // authenticates requests originating from salesforce to matchbot:
            'apiKey' => $this->getNonEmptyStringEnv('SALESFORCE_SECRET_KEY'),
        ];
    }

    /** @return non-empty-string */
    private function getNonEmptyStringEnv(string $varName): string
    {
        $value = getenv($varName);
        assert(is_string($value));
        assert($value !== '');

        return $value;
    }

    private function getStringEnv(string $varName): string
    {
        $value = getenv($varName);
        assert(is_string($value));

        return $value;
    }

    public static function fromEnvVars(): self
    {

        return new self();
    }

    /**
     * @param ApiClient $apiClient
     */
    public function withApiClient(array $apiClient): self
    {
        $settings = clone($this);
        $settings->apiClient = $apiClient;

        return $settings;
    }
}
