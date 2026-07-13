<?php

declare(strict_types=1);

namespace MatchBot\Application\Messenger;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

class Base64PhpSerializer extends PhpSerializer
{
    public function __construct(private LoggerInterface | null $log  = null)
    {
    }

    /**
     * @param array<array-key, mixed> $encodedEnvelope
     */
    #[\Override]
    public function decode(array $encodedEnvelope): Envelope
    {
        if (empty($encodedEnvelope['body']) || !is_string($encodedEnvelope['body'])) {
            throw new \Symfony\Component\Messenger\Exception\MessageDecodingFailedException('Encoded envelope should have at least a "body" string.');
        }

        $body = $encodedEnvelope['body'];

        if (!str_contains($body, '{') && !str_contains($body, ':')) {
            $decodedBody = base64_decode($body, true);
            if (is_string($decodedBody)) {
                $body = $decodedBody;
            }
        }

        // Symfony's PhpSerializer::decode does stripslashes() before unserializing.
        // To counteract this, we addslashes() so the result of stripslashes() is our original data.
        $encodedEnvelope['body'] = addslashes($body);

        try {
            return parent::decode($encodedEnvelope);
        } catch (\Throwable $e) {
            $this->log?->error("Error decoding message, encodedEnvelope body: {$encodedEnvelope['body']}, ");
            throw $e;
        }
    }
}
