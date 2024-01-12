<?php

declare(strict_types=1);

namespace MatchBot\Tests\TestData;

/**
 * Static Identity helpers for both unit & integration tests.
 */
class Identity
{
    public static function getTestPersonNewDonationEndpoint(): string
    {
        return '/v1/people/12345678-1234-1234-1234-1234567890ab/donations';
    }

    /**
     * @see self::getTestIdentityTokenIncomplete()
     */
    public static function getTestIdentityTokenComplete(): string
    {
        // As below but `"complete": true`.
        return 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.' .
            'eyJpc3MiOiJodHRwczovL3VuaXQtdGVzdC1mYWtlLWlkLXN1Yi50aGViaWdnaXZldGVzdC5vcmcudWsiLCJpYXQiOjE2NjM5NDQ4ODks' .
            'ImV4cCI6MjUyNDYwODAwMCwic3ViIjp7InBlcnNvbl9pZCI6IjEyMzQ1Njc4LTEyMzQtMTIzNC0xMjM0LTEyMzQ1Njc4OTBhYiIsImNv' .
            'bXBsZXRlIjp0cnVlLCJwc3BfaWQiOiJjdXNfYWFhYWFhYWFhYWFhMTEifX0.9zk7DUdvC9BWuRhXo2p7r12ZiTuREV7v9zsY97p_fyA';
    }

    public static function getTestIdentityTokenIncomplete(): string
    {
        // One-time, artifically long token generated and hard-coded here so that we don't
        // need live code just for MatchBot to issue ID tokens only for unit tests.
        // Token is for Stripe Customer cus_aaaaaaaaaaaa11.
        //
        // Base 64 decoded body part:
        // {
        //  "iss":"https://unit-test-fake-id-sub.thebiggivetest.org.uk",
        //  "iat":1663436154,
        //  "exp":2524608000,
        //  "sub": {
        //      "person_id":"12345678-1234-1234-1234-1234567890ab",
        //      "complete":false,
        //      "psp_id":"cus_aaaaaaaaaaaa11"
        //  }
        // }
        $dummyPersonAuthTokenValidUntil2050 = 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJpc3MiOiJodHRwczovL3VuaXQtdGVz' .
            'dC1mYWtlLWlkLXN1Yi50aGViaWdnaXZldGVzdC5vcmcudWsiLCJpYXQiOjE2NjM0MzYxNTQsImV4cCI6MjUyNDYwODAwMCwic3ViIjp7' .
            'InBlcnNvbl9pZCI6IjEyMzQ1Njc4LTEyMzQtMTIzNC0xMjM0LTEyMzQ1Njc4OTBhYiIsImNvbXBsZXRlIjpmYWxzZSwicHNwX2lkIjoi' .
            'Y3VzX2FhYWFhYWFhYWFhYTExIn19.KdeGTDkkWCjI4-Kayay0LKn9TXziPXCUxxTPIZgGxxE';

        return $dummyPersonAuthTokenValidUntil2050;
    }
}
