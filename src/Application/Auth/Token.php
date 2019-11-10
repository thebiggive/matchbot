<?php

declare(strict_types=1);

namespace MatchBot\Application\Auth;

use Firebase\JWT\JWT;
use Psr\Log\LoggerInterface;

class Token
{
    /**
     * @link https://stackoverflow.com/questions/39239051/rs256-vs-hs256-whats-the-difference has info on hash
     * algorithm choice. Since we use the secret only server-side and will secure it like other secrets,
     * and symmetric is faster, it's the best and simplest fit for this use case.
     */
    private static $algorithm = 'HS256';

    /**
     * @param string $donationId UUID for a donation
     * @return string Signed JWS
     */
    public static function create(string $donationId): string
    {
        /**
         * @var array $claims
         * @link https://tools.ietf.org/html/rfc7519 has info on the standard keys like `exp`
         */
        $claims = [
            'iss' => getenv('BASE_URI'),
            'iat' => time(),
            'exp' => time() + (30 * 86400), // Expire in 30 days
            'sub' => [
                'donationId' => $donationId,
            ],
        ];

        return JWT::encode($claims, static::getSecret(), static::$algorithm);
    }

    /**
     * @param string            $donationId     UUID for a donation
     * @param string            $jws            Compact JWS (signed JWT)
     * @param LoggerInterface   $logger
     * @return bool Whether the token is valid for the given donation.
     */
    public static function check(string $donationId, string $jws, LoggerInterface $logger): bool
    {
        try {
            $decodedJwtBody = JWT::decode($jws, static::getSecret(), [static::$algorithm]);
        } catch (\Exception $exception) {
            $type = get_class($exception);
            $logger->error("JWT error: decoding for donation ID $donationId: $type - {$exception->getMessage()}");

            return false;
        }

        if ($decodedJwtBody->iss !== getenv('BASE_URI')) {
            $logger->error("JWT error: issued by wrong site {$decodedJwtBody->iss}");

            return false;
        }

        if ($donationId !== $decodedJwtBody->sub->donationId) {
            $logger->error("JWT error: Not authorised for donation ID $donationId");

            return false;
        }

        return true;
    }

    private static function getSecret(): string
    {
        return getenv('JWT_DONATION_SECRET');
    }
}
