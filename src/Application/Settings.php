<?php

namespace MatchBot\Application;

use Doctrine\DBAL\DriverManager as DoctrineDiverManager;
use Monolog\Logger;
use PDO;

/**
 * @readonly
 * @psalm-import-type Params from DoctrineDiverManager
 *
 * @psalm-type ApiClient array{global: array{timeout: string}, salesforce: array{baseUri: string, baseUriCached: string}, mailer: array{baseUri: string, sendSecret: string }}
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

    /** @var array{name: 'matchbot', path: "php://stdout", level: Logger::DEBUG|Logger::INFO} */
    public array $logger;

    /**
     * @var array<string, mixed>
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

    /** @var array{site_key: string, secret_key: string} */
    public array $friendlyCaptchaSettings;

    /**
     * @param array<string, string> $env
     */
    private function __construct(array $env)
    {
        $doctrineConnectionOptions = [];
        $appEnv = $this->getNonEmptyStringEnv($env, 'APP_ENV', true);

        $this->appEnv = $appEnv;
        if (!in_array($appEnv, ['local', 'test'], true)) {
            $doctrineConnectionOptions[PDO::MYSQL_ATTR_SSL_CA] =
                dirname(__DIR__) . '/../deploy/rds-ca-eu-west-1-bundle.pem';
        }

        $this->apiClient = [
            'global' => [
                'timeout' => $this->getStringEnv($env, 'SALESFORCE_CLIENT_TIMEOUT', false), // in seconds
            ],
            'salesforce' => [
                'baseUri' => $this->getStringEnv($env, 'SALESFORCE_API_BASE', false),
                'baseUriCached' => $this->getStringEnv($env, 'SALESFORCE_API_BASE_CACHED', false),
            ],
            'mailer' => [
                'baseUri' => $this->getStringEnv($env, 'MAILER_BASE_URI', false),
                'sendSecret' => $this->getStringEnv($env, 'MAILER_SEND_SECRET', false),
            ],
        ];

        $this->displayErrorDetails = ($appEnv === 'local');

        $this->doctrine = [
            // if true, metadata caching is forcefully disabled
            'dev_mode' => in_array($appEnv, ['local', 'test'], true),

            'cache_dir' => __DIR__ . '/../../var/doctrine',
            'metadata_dirs' => [__DIR__ . '/../../src/Domain'],

            'connection' => [
                'driver' => 'pdo_mysql',
                'host' => $this->getStringEnv($env, 'MYSQL_HOST'),
                'port' => 3306,
                'dbname' => $this->getStringEnv($env, 'MYSQL_SCHEMA'),
                'user' => $this->getStringEnv($env, 'MYSQL_USER'),
                'password' => $this->getStringEnv($env, 'MYSQL_PASSWORD'),
                'charset' => 'utf8mb4',
                'defaultTableOptions' => ['collate' => 'utf8mb4_unicode_ci'],
                'driverOptions' => $doctrineConnectionOptions,
            ],
        ];

        $this->donate = [
            'baseUri' => $this->getStringEnv($env, 'ACCOUNT_MANAGEMENT_BASE_URI', false),
        ];

        $this->identity = [
            'baseUri' => $this->getStringEnv($env, 'ID_BASE_URI', false),
        ];

        $this->logger = [
            'name' => 'matchbot',
            'path' => 'php://stdout',
            'level' => $appEnv === 'local' ? Logger::DEBUG : Logger::INFO,
        ];

        $maxCreatesPerIpPer5M = $env['MAX_CREATES_PER_IP_PER_5M'] ?? null;
        if (is_string($maxCreatesPerIpPer5M)) {
            $this->los_rate_limit = [
                // Dynamic so we can increase it for load tests or as needed based on observed
                // Production behaviour.
                'ip_max_requests' => (int)($maxCreatesPerIpPer5M),
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
                'api_token' => $this->getStringEnv($env, 'SLACK_API_TOKEN', false),
                // e.g. '#matchbot' – channel for app's own general actions.
                'channel' => $this->getStringEnv($env, 'SLACK_CHANNEL', false),
                // Override channel for administrative Stripe notifications.
                'stripe_channel' => 'stripe',
            ],
        ];

        $this->redis = [
            'host' => $this->getNonEmptyStringEnv($env, 'REDIS_HOST', true),
        ];

        $this->stripe = [
            'apiKey' => $this->getNonEmptyStringEnv($env, 'STRIPE_SECRET_KEY', false),
            'accountWebhookSecret' => $this->getStringEnv($env, 'STRIPE_WEBHOOK_SIGNING_SECRET', false),
            'connectAppWebhookSecret' => $this->getStringEnv($env, 'STRIPE_CONNECT_WEBHOOK_SIGNING_SECRET', false),
        ];

        $this->salesforce = [
            // authenticates requests originating from salesforce to matchbot:
            'apiKey' => $this->getNonEmptyStringEnv($env, 'SALESFORCE_SECRET_KEY', false),
        ];

        $this->friendlyCaptchaSettings = [
            'site_key' => $this->getStringEnv($env, 'FRIENDLY_CAPTCHA_SITE_KEY', false),
            'secret_key' =>  $this->getStringEnv($env, 'FRIENDLY_CAPTCHA_SECRET_KEY', false),
        ];
    }

    /** @param array<string, string> $env
     * @return non-empty-string
     */
    private function getNonEmptyStringEnv(array $env, string $varName, bool $throwIfMissing = true): string
    {
        $value = $this->getStringEnv($env, $varName, $throwIfMissing);
        if ($value === '') {
            throw new \Exception("Required environment variable $varName is empty");
        }

        return $value;
    }

    /**
     * @param array<string, string> $env
     */
    private function getStringEnv(array $env, string $varName, bool $throwIfMissing = true): string
    {
        $value = $env[$varName] ?? null;

        if ((! is_string($value))) {
            if ($throwIfMissing) {
                throw new \Exception("Required environment variable $varName is missing.");
            }

            return "Env var $varName not set";
        }


        return $value;
    }

    /**
     * @param array<string, string> $env
     */
    public static function fromEnvVars(array $env): self
    {
        return new self($env);
    }

    /**
     * @param ApiClient $apiClient
     */
    public function withApiClient(array $apiClient): self
    {
        $settings = clone($this);
        $settings->apiClient = $apiClient; // @phpstan-ignore property.readOnlyByPhpDocAssignNotInConstructor

        return $settings;
    }
}
