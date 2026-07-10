<?php

declare(strict_types=1);

namespace MatchBot\Application\Messenger;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

class Base64PhpSerializer extends PhpSerializer
{
    public function decode(array $encodedEnvelope): Envelope
    {
        if (empty($encodedEnvelope['body'])) {
            throw new \Symfony\Component\Messenger\Exception\MessageDecodingFailedException('Encoded envelope should have at least a "body".');
        }

        $body = $encodedEnvelope['body'];

        if (!str_contains($body, '{') && !str_contains($body, ':')) {
            $body = base64_decode($body, true) ?: $body;
        }

        // Symfony's PhpSerializer::decode does stripslashes() before unserializing.
        // To counteract this, we addslashes() so the result of stripslashes() is our original data.
        $encodedEnvelope['body'] = addslashes($body);

        return parent::decode($encodedEnvelope);
    }
}
