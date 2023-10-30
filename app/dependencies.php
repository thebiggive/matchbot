<?php

declare(strict_types=1);

use DI\Container;
use DI\ContainerBuilder;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\RedisCache;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Tools\Setup;
use LosMiddleware\RateLimit\RateLimitMiddleware;
use LosMiddleware\RateLimit\RateLimitOptions;
use MatchBot\Application\Auth;
use MatchBot\Application\Auth\IdentityToken;
use MatchBot\Application\Matching;
use MatchBot\Application\Messenger\Handler\GiftAidResultHandler;
use MatchBot\Application\Messenger\Handler\StripePayoutHandler;
use MatchBot\Application\Messenger\StripePayout;
use MatchBot\Application\Messenger\Transport\ClaimBotTransport;
use MatchBot\Application\Notifier\StripeChatterInterface;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
use MatchBot\Application\SlackChannelChatterFactory;
use MatchBot\Client;
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
use ReCaptcha\ReCaptcha;
use ReCaptcha\RequestMethod\CurlPost;
use Slim\Psr7\Factory\ResponseFactory;
use Stripe\StripeClient;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsTransportFactory;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Messenger\Transport\Sender\SendersLocator;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportFactory;
use Symfony\Component\Messenger\Transport\TransportInterface;
use Symfony\Component\Notifier\Bridge\Slack\SlackTransport;
use Symfony\Component\Notifier\Chatter;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

return function (ContainerBuilder $containerBuilder) {
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
            /** @var SlackChannelChatterFactory $chatterFactory */
            $chatterFactory = $c->get(SlackChannelChatterFactory::class);
            return $chatterFactory->makeChatter($stripeChannel);
        },

        SlackChannelChatterFactory::class => static function (ContainerInterface $c): SlackChannelChatterFactory {
            $settings = $c->get('settings');
            assert(is_array($settings));
            /** @psalm-suppress MixedArrayAccess $token */
            $token = $settings['notifier']['slack']['api_token'];
            assert(is_string($token));
            return new SlackChannelChatterFactory($token);
        },

        ClaimBotTransport::class => static function (ContainerInterface $c): TransportInterface {
            $transportFactory = new TransportFactory([
                new AmazonSqsTransportFactory(),
                new RedisTransportFactory(),
            ]);
            return $transportFactory->createTransport(
                getenv('CLAIMBOT_MESSENGER_TRANSPORT_DSN'),
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

        Client\Fund::class => function (ContainerInterface $c): Client\Fund {
            return new Client\Fund($c->get('settings'), $c->get(LoggerInterface::class));
        },

        Client\Mailer::class => function (ContainerInterface $c): Client\Mailer {
            $settings = $c->get('settings');
            \assert(is_array($settings));
            return new Client\Mailer($settings, $c->get(LoggerInterface::class));
        },

        \Symfony\Component\Clock\ClockInterface::class =>
            function (ContainerInterface $_c): \Symfony\Component\Clock\ClockInterface {
                return new \Symfony\Component\Clock\Clock();
                },

        Client\Stripe::class => function (ContainerInterface $c): Client\Stripe {
            $offTheShelfStripeClient = $c->get(StripeClient::class);

            \assert($offTheShelfStripeClient instanceof StripeClient);

            return new Client\Stripe($offTheShelfStripeClient);
        },

        \MatchBot\Domain\DonationFundsNotifier::class => function (ContainerInterface $c): \MatchBot\Domain\DonationFundsNotifier {
            $mailer = $c->get(Client\Mailer::class);
            \assert($mailer instanceof Client\Mailer);

            $stripeClient = $c->get(Client\Stripe::class);
            \assert($stripeClient instanceof Client\Stripe);

            $clock = $c->get(\Symfony\Component\Clock\ClockInterface::class);
            \assert($clock instanceof \Symfony\Component\Clock\ClockInterface);

            $logger = $c->get(LoggerInterface::class);
            \assert($logger instanceof LoggerInterface);

            return new \MatchBot\Domain\DonationFundsNotifier(
                $mailer,
                $stripeClient,
                $clock,
                $logger,
            );
        },

        EntityManagerInterface::class => function (ContainerInterface $c): EntityManagerInterface {
            return $c->get(RetrySafeEntityManager::class);
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

        LoggerInterface::class => function (ContainerInterface $c): Logger {

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

            $alarmChannelName = match (getenv('APP_ENV')) {
                'production' => 'production-alarms',
                'staging' => 'staging-alarms',
                'regression' => 'regression-alarms',
                default => null,
            };

            if ($alarmChannelName) {
                $slackChannelChatterFactory = $c->get(SlackChannelChatterFactory::class);
                \assert($slackChannelChatterFactory instanceof SlackChannelChatterFactory);

                $logger->pushHandler(
                    new SlackHandler($slackChannelChatterFactory->makeChatter($alarmChannelName))
                );
            }

            return $logger;
        },

        Matching\Adapter::class => static function (ContainerInterface $c): Matching\Adapter {
            $redis = $c->get(Redis::class);
            $entityManager = $c->get(RetrySafeEntityManager::class);
            $logger = $c->get(LoggerInterface::class);

            \assert($redis instanceof Redis);
            \assert($entityManager instanceof RetrySafeEntityManager);
            \assert($logger instanceof LoggerInterface);

            return new Matching\OptimisticRedisAdapter($redis, $entityManager, $logger);
        },

        MessageBusInterface::class => static function (ContainerInterface $c): MessageBusInterface {
            /** @var LoggerInterface $logger */
            $logger = $c->get(LoggerInterface::class);

            $sendMiddleware = new SendMessageMiddleware(new SendersLocator(
                [
                    Messages\Donation::class => [ClaimBotTransport::class],
                    StripePayout::class => [TransportInterface::class],
                ],
                $c,
            ));
            $sendMiddleware->setLogger($logger);

            $handleMiddleware = new HandleMessageMiddleware(new HandlersLocator(
                [
                    Messages\Donation::class => [$c->get(GiftAidResultHandler::class)],
                    StripePayout::class => [$c->get(StripePayoutHandler::class)],
                ],
            ));
            $handleMiddleware->setLogger($logger);

            return new MessageBus([
                $sendMiddleware,
                $handleMiddleware,
            ]);
        },

        ORM\Configuration::class => static function (ContainerInterface $c): ORM\Configuration {
            $settings = $c->get('settings');

            // Must be a distinct instance from the one used for fund allocation maths, as Doctrine's PHP serialisation
            // is incompatible with that needed for Redis commands that modify integer values in place. This injected
            // Configuration is never re-created even when the EntityManager is, so for now we can safely do this here
            // on construct.
            $redis = new Redis();
            try {
                $redis->connect($settings['redis']['host']);
                $cache = new RedisCache();
                $cache->setRedis($redis);
                $cache->setNamespace("matchbot-{$settings['appEnv']}");
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
                $cache = new ArrayCache();
            }

            $config = Setup::createAnnotationMetadataConfiguration(
                $settings['doctrine']['metadata_dirs'],
                $settings['doctrine']['dev_mode'],
                $settings['doctrine']['cache_dir'] . '/proxies',
                $cache
            );

            // Turn off auto-proxies in ECS envs, where we explicitly generate them on startup entrypoint and cache all
            // files indefinitely.
            $config->setAutoGenerateProxyClasses($settings['doctrine']['dev_mode']);

            $config->setMetadataDriverImpl(
                new AnnotationDriver(new AnnotationReader(), $settings['doctrine']['metadata_dirs'])
            );

            $config->setMetadataCacheImpl($cache);

            return $config;
        },

        ProblemDetailsResponseFactory::class => static function (ContainerInterface $c): ProblemDetailsResponseFactory {
            return new ProblemDetailsResponseFactory(new ResponseFactory());
        },

        RateLimitMiddleware::class => static function (ContainerInterface $c): RateLimitMiddleware {
            return new RateLimitMiddleware(
                $c->get(CacheInterface::class),
                $c->get(ProblemDetailsResponseFactory::class),
                new RateLimitOptions($c->get('settings')['los_rate_limit']),
            );
        },

        ReCaptcha::class => static function (ContainerInterface $c): ReCaptcha {
            return new ReCaptcha($c->get('settings')['recaptcha']['secret_key'], new CurlPost());
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

        RetrySafeEntityManager::class => static function (ContainerInterface $c): RetrySafeEntityManager {
            $logger = $c->get(LoggerInterface::class);
            \assert($logger instanceof LoggerInterface);

            return new RetrySafeEntityManager(
                $c->get(ORM\Configuration::class),
                $c->get('settings')['doctrine']['connection'],
                $logger,
            );
        },

        RoutableMessageBus::class => static function (ContainerInterface $c): RoutableMessageBus {
            $busContainer = new Container();
            $busContainer->set('claimbot.donation.claim', $c->get(MessageBusInterface::class));
            $busContainer->set('claimbot.donation.result', $c->get(MessageBusInterface::class));
            $busContainer->set(\Stripe\Event::PAYOUT_PAID, $c->get(MessageBusInterface::class));

            return new RoutableMessageBus($busContainer);
        },

        SerializerInterface::class => static function (ContainerInterface $c): SerializerInterface {
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
            return new StripeClient([
                'api_key' => $c->get('settings')['stripe']['apiKey'],
                'stripe_version' => $c->get('settings')['stripe']['apiVersion'],
            ]);
        },

        TransportInterface::class => static function (ContainerInterface $c): TransportInterface {
            $transportFactory = new TransportFactory([
                new AmazonSqsTransportFactory(),
                new RedisTransportFactory(),
            ]);
            return $transportFactory->createTransport(
                getenv('MESSENGER_TRANSPORT_DSN'),
                [],
                new PhpSerializer(),
            );
        },
    ]);
};
