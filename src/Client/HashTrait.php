<?php

declare(strict_types=1);

namespace MatchBot\Client;

trait HashTrait
{
    /**
     * @return array<string, string>
     */
    private function getVerifyHeaders(string $json): array
    {
        return ['X-Webhook-Verify-Hash' => $this->hash($json)];
    }

    private function hash(string $body): string
    {
        $secret = getenv('WEBHOOK_DONATION_SECRET');
        if ($secret === false) {
            throw new \Exception("Missing webhook donation secret");
        }

        return hash_hmac('sha256', trim($body), $secret);
    }
}
