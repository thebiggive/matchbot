<?php

declare(strict_types=1);

namespace MatchBot\Tests\TestData;

use Firebase\JWT\JWT;

/**
 * Static Identity helpers for both unit & integration tests.
 */
class Identity
{
    private const array INCOMPLETE_TOKEN_PAYLOAD = [
        "iss" => "https://unit-test-fake-id-sub.thebiggivetest.org.uk",
        "iat" => 1663436154,
        "exp" => 2524608000,
        "sub" => [
            "person_id" => "12345678-1234-1234-1234-1234567890ab",
            "complete" => false,
            "psp_id" => "cus_aaaaaaaaaaaa11",
        ]
    ];

    public static function getTestPersonNewDonationEndpoint(): string
    {
        return '/v1/people/12345678-1234-1234-1234-1234567890ab/donations';
    }

    public static function getTestPersonMandateEndpoint(): string
    {
        return '/v1/people/12345678-1234-1234-1234-1234567890ab/regular-giving';
    }

    /**
     * @see self::getTestIdentityTokenIncomplete()
     */
    public static function getTestIdentityTokenComplete(): string
    {
        $token = self::INCOMPLETE_TOKEN_PAYLOAD;
        $token['sub']['complete'] = true;

        return JWT::encode(
            $token,
            self::secret(),
            'HS256',
        );
    }

    public static function getTestIdentityTokenIncomplete(): string
    {
        return JWT::encode(
            self::INCOMPLETE_TOKEN_PAYLOAD,
            self::secret(),
            'HS256',
        );
    }

    public static function secret(): string
    {
        $secret = getenv('JWT_ID_SECRET');
        \assert(is_string($secret));

        return $secret;
    }
}
