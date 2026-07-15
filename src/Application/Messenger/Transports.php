<?php

namespace MatchBot\Application\Messenger;

use Symfony\Component\Messenger\Bridge\AmazonSqs\Transport\AmazonSqsTransportFactory;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransportFactory;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportFactory;
use Symfony\Component\Messenger\Transport\TransportInterface;

class Transports
{
    public const string TRANSPORT_CLAIMBOT = 'transport_claimbot';
    public const string TRANSPORT_LOW_PRIORITY = 'transport_low_priority';
    public const string TRANSPORT_HIGH_PRIORITY = 'transport_high_priority';

    private const array DEFINITIONS = [
        self::TRANSPORT_CLAIMBOT => [
            'dsn_var' => 'CLAIMBOT_MESSENGER_TRANSPORT_DSN',
            'supports_sqs' => false,
        ],
        self::TRANSPORT_LOW_PRIORITY => [
            'dsn_var' => 'LOW_PRIORITY_MESSENGER_TRANSPORT_DSN',
            'supports_sqs' => true,
        ],
        self::TRANSPORT_HIGH_PRIORITY => [
            'dsn_var' => 'MESSENGER_TRANSPORT_DSN',
            'supports_sqs' => true,
        ],
    ];

    public static function buildTransport(string $key): TransportInterface
    {
        if (!isset(self::DEFINITIONS[$key])) {
            throw new \InvalidArgumentException("Transport key {$key} is not defined");
        }

        $dsn = getenv(self::DEFINITIONS[$key]['dsn_var']);
        if ($dsn === false) {
            throw new \RuntimeException("Environment variable " . self::DEFINITIONS[$key]['dsn_var'] . " is not set");
        }

        $transportFactory = new TransportFactory([
            new InMemoryTransportFactory(), // For unit tests.
            new RedisTransportFactory(),
            ...(self::DEFINITIONS[$key]['supports_sqs'] ? [new AmazonSqsTransportFactory()] : []),
        ]);

        return $transportFactory->createTransport($dsn, [], new PhpSerializer());
    }
}
