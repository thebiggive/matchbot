<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\ORM\Tools\Setup;
use MatchBot\Application\Auth;
use MatchBot\Client;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\Factory as LockFactory;
use Symfony\Component\Lock\Store\PdoStore;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        Auth\DonationHookAuthMiddleware::class => function (ContainerInterface $c): Auth\DonationHookAuthMiddleware {
            return new Auth\DonationHookAuthMiddleware($c->get(LoggerInterface::class));
        },

        Auth\DonationPublicAuthMiddleware::class
            => function (ContainerInterface $c): Auth\DonationPublicAuthMiddleware {
                return new Auth\DonationPublicAuthMiddleware($c->get(LoggerInterface::class));
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

        EntityManagerInterface::class => function (ContainerInterface $c): EntityManagerInterface {
            $settings = $c->get('settings');

            $config = Setup::createAnnotationMetadataConfiguration(
                $settings['doctrine']['metadata_dirs'],
                $settings['doctrine']['dev_mode']
            );

            $config->setMetadataDriverImpl(
                new AnnotationDriver(
                    new AnnotationReader(),
                    $settings['doctrine']['metadata_dirs']
                )
            );

            $config->setMetadataCacheImpl(
                new FilesystemCache(
                    $settings['doctrine']['cache_dir']
                )
            );

            return EntityManager::create(
                $settings['doctrine']['connection'],
                $config
            );
        },

        LockFactory::class => function (ContainerInterface $c): LockFactory {
            $em = $c->get(EntityManagerInterface::class);
            $lockStore = new PdoStore($em->getConnection(), ['db_table' => 'CommandLockKeys']);

            return new LockFactory($lockStore);
        },

        LoggerInterface::class => function (ContainerInterface $c) {
            $settings = $c->get('settings');

            $loggerSettings = $settings['logger'];
            $logger = new Logger($loggerSettings['name']);

            $processor = new UidProcessor();
            $logger->pushProcessor($processor);

            $handler = new StreamHandler($loggerSettings['path'], $loggerSettings['level']);
            $logger->pushHandler($handler);

            return $logger;
        },

        SerializerInterface::class => static function (ContainerInterface $c) {
            $encoders = [new JsonEncoder()];
            $normalizers = [new ObjectNormalizer()];

            return new Serializer($normalizers, $encoders);
        }
    ]);
};
