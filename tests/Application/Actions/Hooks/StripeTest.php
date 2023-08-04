<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Hooks;

use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;

abstract class StripeTest extends TestCase
{
    use DonationTestDataTrait;

    public static function generateSignature(string $time, string $body, string $webhookSecret): string
    {
        return 't=' . $time . ',' . 'v1=' . self::getValidAuth(self::getSignedPayload($time, $body), $webhookSecret);
    }

    protected static function getSignedPayload(string $time, string $body): string
    {
        $payloadinTest = "$time.$body";
//        var_dump(compact('payloadinTest'));
        return $payloadinTest;
    }

    protected static function getValidAuth(string $signedPayload, string $webhookSecret): string
    {
        return hash_hmac('sha256', $signedPayload, $webhookSecret);
    }
}
