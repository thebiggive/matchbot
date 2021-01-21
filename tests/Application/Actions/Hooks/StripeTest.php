<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Hooks;

use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;

abstract class StripeTest extends TestCase
{
    use DonationTestDataTrait;

    protected function generateSignature(string $time, string $body, string $webhookSecret): string
    {
        return 't=' . $time . ',' . 'v1=' . $this->getValidAuth($this->getSignedPayload($time, $body), $webhookSecret);
    }

    private function getSignedPayload(string $time, string $body): string
    {
        return "$time.$body";
    }

    private function getValidAuth(string $signedPayload, string $webhookSecret): string
    {
        return hash_hmac('sha256', $signedPayload, $webhookSecret);
    }
}
