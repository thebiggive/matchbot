<?php

declare(strict_types=1);

use Aws\CloudWatch\CloudWatchClient;
use DI\Container;
use DI\ContainerBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager as DBALDriverManager;
use Doctrine\ORM;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Los\RateLimit\RateLimitMiddleware;
use Los\RateLimit\RateLimitOptions;
use MatchBot\Application\Auth;
use MatchBot\Application\Auth\IdentityToken;
use MatchBot\Application\Environment;
use MatchBot\Application\Matching;
use MatchBot\Application\Messenger\CharityUpdated;
use MatchBot\Application\Messenger\DonationUpserted;
use MatchBot\Application\Messenger\FundTotalUpdated;
use MatchBot\Application\Messenger\Handler\CharityUpdatedHandler;
use MatchBot\Application\Messenger\Handler\DonationUpsertedHandler;
use MatchBot\Application\Messenger\Handler\FundTotalUpdatedHandler;
use MatchBot\Application\Messenger\Handler\GiftAidResultHandler;
use MatchBot\Application\Messenger\Handler\MandateUpsertedHandler;
use MatchBot\Application\Messenger\Handler\PersonHandler;
use MatchBot\Application\Messenger\Handler\StripePayoutHandler;
use MatchBot\Application\Messenger\MandateUpserted;
use MatchBot\Application\Messenger\Middleware\AddOrLogMessageId;
use MatchBot\Application\Messenger\StripePayout;
use MatchBot\Application\Messenger\Transport\ClaimBotTransport;
use MatchBot\Application\Notifier\StripeChatterInterface;
use MatchBot\Application\Persistence\RegularGivingMandateEventSubscriber;
use MatchBot\Application\RealTimeMatchingStorage;
use MatchBot\Application\RedisMatchingStorage;
use MatchBot\Application\SlackChannelChatterFactory;
use MatchBot\Client;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DonationFundsNotifier;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Monolog\Handler\SlackHandler;
use MatchBot\Monolog\Processor\AwsTraceIdProcessor;
use Mezzio\ProblemDetails\ProblemDetailsResponseFactory;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\MemoryPeakUsageProcessor;
use Monolog\Processor\UidProcessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Stripe\StripeClient;
use Stripe\Util\ApiVersion;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Symfony\Component\Messenger\Bridge\AmazonSqs\Middleware\AddFifoStampMiddleware;
use Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsTransportFactory;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransportFactory;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportFactory;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Notifier\Bridge\Slack\SlackTransport;
use Symfony\Component\Notifier\Chatter;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

return function (ContainerBuilder $containerBuilder) {
    // When writing closures within this function do not use `use` or implicit binding of arrow functions to bring in
    // variables - that works in the dev
    // env where we use the non-compiled container but not in prod or prod-like envs where it causes an error
    // "Cannot compile closures which import variables using the `use` keyword"
    // https://github.com/PHP-DI/PHP-DI/blob/a7410e4ee4f61312183af2d7e26a9e6592d2d974/src/Compiler/Compiler.php#L389

    $containerBuilder->addDefinitions([
        Auth\DonationPublicAuthMiddleware::class =>
            function (ContainerInterface $c): Auth\DonationPublicAuthMiddleware {
                return new Auth\DonationPublicAuthMiddleware($c->get(LoggerInterface::class));
            },

        CacheInterface::class => function (ContainerInterface $c): CacheInterface {
            return new Psr16Cache(
                new Symfony\Component\Cache\Adapter\RedisAdapter(
                    $c->get(Redis::class),
                    // Distinguish e.g. rate limit data from matching if we ever need to debug
                    // or clear Redis contents.
                    'matchbot-cache',
                    3600, // Allow Auto-clearing cache/rate limit data after an hour.
                ),
            );
        },

        ChatterInterface::class => static function (ContainerInterface $c): ChatterInterface {
            $settings = $c->get('settings');
            $transport = new SlackTransport(
                $settings['notifier']['slack']['api_token'],
                $settings['notifier']['slack']['channel'],
            );

            return new Chatter($transport);
        },

        ConsumeMessagesCommand::class => static function (ContainerInterface $c): ConsumeMessagesCommand {
            $messengerReceiverKey = 'receiver';
            $messengerReceiverLocator = new Container();
            $messengerReceiverLocator->set($messengerReceiverKey, $c->get(TransportInterface::class));

            return new ConsumeMessagesCommand(
                $c->get(RoutableMessageBus::class),
                $messengerReceiverLocator,
                new EventDispatcher(),
                $c->get(LoggerInterface::class),
                [$messengerReceiverKey],
            );
        },

        // Don't inject this directly for now, since its return type doesn't actually implement
        // our custom interface. We're working around needing two services with distinct channels.
        StripeChatterInterface::class => static function (ContainerInterface $c): ChatterInterface {
            /**
             * @var array{
             *    notifier: array{
             *      slack: array{
             *        stripe_channel: string
             *      }
             *    }
             *  } $settings
             */
            $settings = $c->get('settings');
            $stripeChannel = $settings['notifier']['slack']['stripe_channel'];

            return $c->get(SlackChannelChatterFactory::class)->makeChatter($stripeChannel);
        },

        SlackChannelChatterFactory::class => static function (ContainerInterface $c): SlackChannelChatterFactory {
            $settings = $c->get('settings');
            assert(is_array($settings));
            /** @psalm-suppress MixedArrayAccess $token */
            $token = $settings['notifier']['slack']['api_token'];
            assert(is_string($token));
            return new SlackChannelChatterFactory($token);
        },

        ClaimBotTransport::class => static function (): TransportInterface {
            $transportFactory = new TransportFactory([
                new AmazonSqsTransportFactory(),
                new RedisTransportFactory(),
            ]);
            $claimbotDSN = getenv('CLAIMBOT_MESSENGER_TRANSPORT_DSN');
            if ($claimbotDSN === false) {
                throw new \Exception('CLAIMBOT_MESSENGER_TRANSPORT_DSN must be defined in environment');
            }
            return $transportFactory->createTransport(
                $claimbotDSN,
                [],
                new PhpSerializer(),
            );
        },

        Client\Campaign::class => function (ContainerInterface $c): Client\Campaign {
            return new Client\Campaign($c->get('settings'), $c->get(LoggerInterface::class));
        },

        Client\Donation::class => function (ContainerInterface $c): Client\Donation {
            return new Client\Donation($c->get('settings'), $c->get(LoggerInterface::class));
        },

        Client\Mandate::class => function (ContainerInterface $c): Client\Mandate {
            return new Client\Mandate($c->get('settings'), $c->get(LoggerInterface::class));
        },

        Client\Fund::class => function (ContainerInterface $c): Client\Fund {
            $settings = $c->get('settings');
            \assert(is_array($settings));
            return new Client\Fund($settings, $c->get(LoggerInterface::class));
        },

        Client\Mailer::class => function (ContainerInterface $c): Client\Mailer {
            $settings = $c->get('settings');
            \assert(is_array($settings));
            return new Client\Mailer($settings, $c->get(LoggerInterface::class));
        },

        Client\Stripe::class => function (ContainerInterface $c): Client\Stripe {
            $isLoadTest = getenv('APP_ENV') !== 'production' && isset($_SERVER['HTTP_X_IS_LOAD_TEST']);
            if ($isLoadTest) {
                return new Client\StubStripeClient();
            }

            return new Client\LiveStripeClient($c->get(StripeClient::class));
        },

        CloudWatchClient::class => static function (): CloudWatchClient {
            return new CloudWatchClient([
                'version' => 'latest',
                'region' => getenv('AWS_REGION'),
                'credentials' => [
                    'key' => getenv('AWS_CLOUDWATCH_ACCESS_KEY_ID'),
                    'secret' => getenv('AWS_CLOUDWATCH_SECRET_ACCESS_KEY'),
                ],
            ]);
        },

        DonationFundsNotifier::class => function (ContainerInterface $c): DonationFundsNotifier {
            return new DonationFundsNotifier($c->get(Client\Mailer::class));
        },

        EntityManagerInterface::class => function (ContainerInterface $c): EntityManagerInterface {
            return $c->get(ORM\EntityManager::class);
        },

        IdentityToken::class => function (ContainerInterface $c): IdentityToken {
            return new IdentityToken($c->get('settings')['identity']['baseUri']);
        },

        LockFactory::class => function (ContainerInterface $c): LockFactory {
            $em = $c->get(EntityManagerInterface::class);
            $lockStore = new DoctrineDbalStore($em->getConnection(), ['db_table' => 'CommandLockKeys']);
            $factory = new LockFactory($lockStore);
            $factory->setLogger($c->get(LoggerInterface::class));

            return $factory;
        },

        Logger::class => function (ContainerInterface $c): Logger {

            $commitId = $c->get('commit-id');
            \assert(is_string($commitId));

            $settings = $c->get('settings');

            $loggerSettings = $settings['logger'];
            $logger = new Logger($loggerSettings['name']);

            $awsTraceIdProcessor = new AwsTraceIdProcessor();
            $logger->pushProcessor($awsTraceIdProcessor);

            $memoryPeakProcessor = new MemoryPeakUsageProcessor();
            $logger->pushProcessor($memoryPeakProcessor);

            $uidProcessor = new UidProcessor();
            $logger->pushProcessor($uidProcessor);

            $handler = new StreamHandler($loggerSettings['path'], $loggerSettings['level']);
            $logger->pushHandler($handler);

            $logger->pushProcessor(new class ($commitId) implements Monolog\Processor\ProcessorInterface
            {
                public function __construct(private string $commit_id)
                {
                }
                public function __invoke(array $record)
                {
                    $record['extra']['commit'] = substr($this->commit_id, offset: 0, length: 7);
                    return $record;
                }
            });

            $alarmChannelName = match (getenv('APP_ENV')) {
                'production' => 'production-alarms',
                'staging' => 'staging-alarms',
                'regression' => 'regression-alarms',
                default => null,
            };

            if ($alarmChannelName) {
                $logger->pushHandler(
                    new SlackHandler($c->get(SlackChannelChatterFactory::class)->makeChatter($alarmChannelName))
                );
            }

            return $logger;
        },

        LoggerInterface::class => fn (ContainerInterface $c): LoggerInterface => $c->get(Logger::class),

        Environment::class => function (ContainerInterface $_c): Environment {
            /** @psalm-suppress PossiblyFalseArgument - we expect APP_ENV to be set everywhere */
            return Environment::fromAppEnv(getenv('APP_ENV'));
        },

        'donation-creation-rate-limiter-factory' => function (ContainerInterface $c): RateLimiterFactory {
            return new RateLimiterFactory(
                config: [
                    'id' => 'create-donation',
                    'policy' => 'token_bucket',

                    // how many donations a new user can create within their first second on the site
                    // If they are creating these 5 manually over a few minutes then they should accrue
                    // rate limit credits to make another 5 or so before they run out.
                    'limit' => 5,

                    // how often they can create new donations once the initial allowance is used up.
                    'rate' => ['interval' => '30 seconds'],
                ],
                storage: new CacheStorage(new RedisAdapter($c->get(Redis::class)))
            );
        },

        RealTimeMatchingStorage::class => static function (ContainerInterface $c): RealTimeMatchingStorage {
            return new RedisMatchingStorage($c->get(Redis::class));
        },

        Matching\Adapter::class =>
            static function (ContainerInterface $c): Matching\Adapter {
                return new Matching\Adapter(
                    $c->get(RealTimeMatchingStorage::class),
                    $c->get(LoggerInterface::class)
                );
            },

        MessageBusInterface::class => static function (ContainerInterface $c): MessageBusInterface {
            $logger = $c->get(LoggerInterface::class);

            $sendersLocator = new SendersLocator(
                [
                    Messages\Donation::class => [ClaimBotTransport::class],
                    CharityUpdated::class => [TransportInterface::class],
                    StripePayout::class => [TransportInterface::class],
                    DonationUpserted::class => [TransportInterface::class],
                    MandateUpserted::class => [TransportInterface::class],
                    FundTotalUpdated::class => [TransportInterface::class],
                ],
                $c,
            );

            $sendMiddleware = new SendMessageMiddleware($sendersLocator, allowNoSenders: false);
            $sendMiddleware->setLogger($logger);

            /**
             * @psalm-suppress MixedArgument
             * @psalm-suppress MissingClosureParamType
             */
            $handleMiddleware = new HandleMessageMiddleware(new HandlersLocator(
                /** We lazy-load the handlers from the container to avoid circular dependencies. */
                [
                    CharityUpdated::class => [fn($msg) => $c->get(CharityUpdatedHandler::class)($msg)],
                    Messages\Donation::class => [fn($msg) => $c->get(GiftAidResultHandler::class)($msg)],
                    Messages\Person::class => [fn($msg) => $c->get(PersonHandler::class)($msg)],
                    StripePayout::class => [fn($msg) => $c->get(StripePayoutHandler::class)($msg)],
                    DonationUpserted::class => [fn($msg) => $c->get(DonationUpsertedHandler::class)($msg)],
                    MandateUpserted::class => [fn($msg) => $c->get(MandateUpsertedHandler::class)($msg)],
                    FundTotalUpdated::class => [fn($msg) => $c->get(FundTotalUpdatedHandler::class)($msg)],
                ],
            ));
            $handleMiddleware->setLogger($logger);

            return new MessageBus([
                new AddFifoStampMiddleware(),
                new AddOrLogMessageId($logger),
                $sendMiddleware,
                $handleMiddleware,
            ]);
        },

        'commit-id' => static fn(ContainerInterface $_c): string => require __DIR__ . "/../.build-commit-id.php",

        ORM\Configuration::class => static function (ContainerInterface $c): ORM\Configuration {
            $settings = $c->get('settings');
            $commitId = $c->get('commit-id');
            \assert(is_string($commitId));

            // Must be a distinct instance from the one used for fund allocation maths, as Doctrine's PHP serialisation
            // is incompatible with that needed for Redis commands that modify integer values in place. This injected
            // Configuration is never re-created even when the EntityManager is, so for now we can safely do this here
            // on construct.
            $redis = new Redis();
            try {
                $redis->connect($settings['redis']['host']);
                $cacheAdapter = new RedisAdapter(
                    redis: $redis,
                    namespace: "matchbot-{$settings['appEnv']}-{$commitId}"
                );
            } catch (RedisException $exception) {
                // This essentially means Doctrine is not using a cache. `/ping` should fail separately based on
                // Redis being down whenever this happens, so we should find out without relying on this warning log.
                $logger = $c->get(LoggerInterface::class);
                $logger->warning(
                    'Doctrine falling back to array cache - Redis host ' . $c->get('settings')['redis']['host']
                );
                $logger->error(sprintf(
                    'Doctrine bootstrap error %s: %s',
                    get_class($exception),
                    $exception->getMessage(),
                ));
                $cacheAdapter = new ArrayAdapter();
            }

            $config = ORM\ORMSetup::createAttributeMetadataConfiguration(
                $settings['doctrine']['metadata_dirs'],
                $settings['doctrine']['dev_mode'],
                $settings['doctrine']['cache_dir'] . '/proxies',
                $cacheAdapter,
            );

            // Turn off auto-proxies in ECS envs, where we explicitly generate them on startup entrypoint and cache all
            // files indefinitely.
            $config->setAutoGenerateProxyClasses($settings['doctrine']['dev_mode']);

            $config->setMetadataDriverImpl(
                new ORM\Mapping\Driver\AttributeDriver($settings['doctrine']['metadata_dirs'])
            );

            $config->setMetadataCache($cacheAdapter);

            return $config;
        },

        ProblemDetailsResponseFactory::class => static function (): ProblemDetailsResponseFactory {
            return new ProblemDetailsResponseFactory(new ResponseFactory());
        },

        RateLimitMiddleware::class => static function (ContainerInterface $c): RateLimitMiddleware {
            return new RateLimitMiddleware(
                $c->get(CacheInterface::class),
                $c->get(ProblemDetailsResponseFactory::class),
                new RateLimitOptions($c->get('settings')['los_rate_limit']),
            );
        },

        /**
         * Do NOT pass this instance to Doctrine, which will set it to PHP serialisation, breaking incr/decr maths
         * which is *CRITICAL FOR MATCHING*!
         */
        Redis::class => static function (ContainerInterface $c): ?Redis {
            $redis = new Redis();
            try {
                $connected = $redis->connect($c->get('settings')['redis']['host']);
                if ($connected) {
                    // Required for incr/decr commands
                    $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_NONE);
                }
            } catch (RedisException $exception) {
                $c->get(LoggerInterface::class)->warning(sprintf(
                    'Redis connect() got RedisException: "%s". Host %s',
                    $exception->getMessage(),
                    $c->get('settings')['redis']['host'],
                ));

                return null;
            }

            return $redis;
        },

        ORM\EntityManager::class =>  static function (ContainerInterface $c): EntityManager {
            $connection = DBALDriverManager::getConnection($c->get('settings')['doctrine']['connection']);
            $config = $c->get(ORM\Configuration::class);

            $em = new ORM\EntityManager(conn: $connection, config: $config);

            $em->getEventManager()->addEventSubscriber($c->get(RegularGivingMandateEventSubscriber::class));

            return $em;
        },

        RoutableMessageBus::class => static function (ContainerInterface $c): RoutableMessageBus {
            $busContainer = new Container();
            $bus = $c->get(MessageBusInterface::class);

            $busContainer->set('claimbot.donation.claim', $bus);
            $busContainer->set('claimbot.donation.result', $bus);
            $busContainer->set(\Stripe\Event::PAYOUT_PAID, $bus);

            /**
             * Every message defaults to our only bus, so we think these are technically redundant for
             * now. The list is not exhaustive.
             */
            $busContainer->set('claimbot.donation.claim', $bus);
            $busContainer->set('claimbot.donation.result', $bus);
            $busContainer->set(\Stripe\Event::PAYOUT_PAID, $bus);
            $busContainer->set(CharityUpdated::class, $bus);
            $busContainer->set(DonationUpserted::class, $bus);
            $busContainer->set(FundTotalUpdated::class, $bus);

            return new RoutableMessageBus($busContainer, $bus);
        },

        SerializerInterface::class => static function (): SerializerInterface {
            $encoders = [new JsonEncoder()];
            $normalizers = [
                new BackedEnumNormalizer(),
                new ObjectNormalizer(),
            ];

            return new Serializer($normalizers, $encoders);
        },

        // StripeClientInterface doesn't have enough properties documented to keep
        // psalm happy.
        StripeClient::class => static function (ContainerInterface $c): StripeClient {
            // Both hardcoding the version and using library default - see discussion at
            // https://github.com/thebiggive/matchbot/pull/927/files/5fa930f3eee3b0c919bcc1027319dc7ae9d0be05#diff-c4fef49ee08946228bb39de898c8770a1a6a8610fc281627541ec2e49c67b118
            \assert(ApiVersion::CURRENT === '2024-06-20');
            return new StripeClient([
                'api_key' => $c->get('settings')['stripe']['apiKey'],
                'stripe_version' => ApiVersion::CURRENT,
            ]);
        },

        TransportInterface::class => static function (): TransportInterface {
            $transportFactory = new TransportFactory([
                new AmazonSqsTransportFactory(),
                new InMemoryTransportFactory(), // For unit tests.
                new RedisTransportFactory(),
            ]);
            $dsn = getenv('MESSENGER_TRANSPORT_DSN');
            if ($dsn === false) {
                throw new \Exception('MESSENGER_TRANSPORT_DSN not defined in enviornmnet');
            }
            return $transportFactory->createTransport(
                $dsn,
                [],
                new PhpSerializer(),
            );
        },
        Connection::class => static function (ContainerInterface $c): Connection {
            return $c->get(EntityManagerInterface::class)->getConnection();
        },

        ClockInterface::class => fn() => new NativeClock(),
        Psr\Clock\ClockInterface::class  => fn() => new NativeClock(),

        EventDispatcherInterface::class => fn() => new EventDispatcher(),

        Auth\SalesforceAuthMiddleware::class =>
            function (ContainerInterface $c) {
               /**
                * @psalm-suppress MixedArrayAccess
                * @psalm-suppress MixedArgument
                */
                return new Auth\SalesforceAuthMiddleware(
                    sfApiKey: $c->get('settings')['salesforce']['apiKey'],
                    logger: $c->get(LoggerInterface::class)
                );
            },

        DonationService::class =>
            static function (ContainerInterface $c): DonationService {
            /**
             * @var ChatterInterface $chatter
             * Injecting `StripeChatterInterface` directly doesn't work because `Chatter` itself
             * is final and does not implement our custom interface.
             */
                $chatter = $c->get(StripeChatterInterface::class);

                $rateLimiterFactory = $c->get('donation-creation-rate-limiter-factory');
                \assert($rateLimiterFactory instanceof RateLimiterFactory);

                return new DonationService(
                    donationRepository: $c->get(DonationRepository::class),
                    campaignRepository: $c->get(CampaignRepository::class),
                    logger: $c->get(LoggerInterface::class),
                    entityManager: $c->get(EntityManagerInterface::class),
                    stripe: $c->get(\MatchBot\Client\Stripe::class),
                    matchingAdapter: $c->get(Matching\Adapter::class),
                    chatter: $chatter,
                    clock: $c->get(ClockInterface::class),
                    rateLimiterFactory: $rateLimiterFactory,
                    donorAccountRepository: $c->get(DonorAccountRepository::class),
                    bus: $c->get(RoutableMessageBus::class),
                );
            }
    ]);
};
