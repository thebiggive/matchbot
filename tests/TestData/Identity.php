<?php

declare(strict_types=1);

namespace MatchBot\Tests\TestData;

use Firebase\JWT\JWT;
use MatchBot\Domain\Country;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Domain\StripePaymentMethodId;

/**
 * Static Identity helpers for both unit & integration tests.
 */
class Identity
{
    public const string IDENTITY_UUID = "12345678-1234-1234-1234-1234567890ab";
    public const string STRIPE_ID = "cus_aaaaaaaaaaaa11";

    private const array INCOMPLETE_TOKEN_PAYLOAD = [
        "iss" => "https://unit-test-fake-id-sub.thebiggivetest.org.uk",
        "iat" => 1663436154,
        "exp" => 2524608000,
        "sub" => [
            "person_id" => self::IDENTITY_UUID,
            "complete" => false,
            "psp_id" => self::STRIPE_ID,
        ]
    ];



    public static function getTestPersonNewDonationEndpoint(): string
    {
        return '/v1/people/' . self::IDENTITY_UUID . '/donations';
    }

    public static function getTestPersonMandateEndpoint(): string
    {
        return '/v1/people/' . self::IDENTITY_UUID . '/regular-giving';
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

    public static function donorAccount(): DonorAccount
    {
        $donorAccount = new DonorAccount(
            PersonId::of(self::IDENTITY_UUID),
            EmailAddress::of('email@example.com'),
            DonorName::of('John', 'Doe'),
            StripeCustomerId::of(self::STRIPE_ID),
        );
        $donorAccount->setBillingPostcode('E17');
        $donorAccount->setBillingCountry(Country::GB());
        $donorAccount->setRegularGivingPaymentMethod(StripePaymentMethodId::of('pm_x'));

        return $donorAccount;
    }
}
