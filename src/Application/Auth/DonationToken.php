<?php

declare(strict_types=1);

namespace MatchBot\Application\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use JetBrains\PhpStorm\Pure;
use Psr\Log\LoggerInterface;

final class DonationToken
{
    /**
     * @link https://stackoverflow.com/questions/39239051/rs256-vs-hs256-whats-the-difference has info on hash
     * algorithm choice. Since we use the secret only server-side and will secure it like other secrets,
     * and symmetric is faster, it's the best and simplest fit for this use case.
     */
    private static string $algorithm = 'HS256';

    /**
     * @param string $donationId UUID for a donation
     * @return string Signed JWS
     */
    public static function create(string $donationId): string
    {
        /**
         * @var array<mixed> $claims
         * @link https://tools.ietf.org/html/rfc7519 has info on the standard keys like `exp`
         */
        $claims = [
            'iss' => getenv('BASE_URI'),
            'iat' => time(),
            'exp' => time() + 8 * 60 * 60, // eight hours
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
        $key = new Key(static::getSecret(), static::$algorithm);
        try {
            $decodedJwtBody = JWT::decode($jws, $key);
        } catch (\Exception $exception) {
            $type = get_class($exception);
            // This is only a notice. We've seen likely crawlers + bots send invalid requests that
            // get decode exceptions. Likely real donors also occasionally get here with expired tokens.
            $logger->notice("JWT error: decoding for donation ID $donationId: $type - {$exception->getMessage()}");

            return false;
        }

        if ($decodedJwtBody->iss !== getenv('BASE_URI')) {
            $logger->error("JWT error: issued by wrong site {$decodedJwtBody->iss}");

            return false;
        }

        /** @var object{donationId: string} $sub */
        $sub = $decodedJwtBody->sub;

        if ($donationId !== $sub->donationId) {
            // We've seen this rarely from things like sharing thank you URLs across browsers / devices.
            // We want stats of this on dashboards to monitor frequency, but not error alarms.
            $logger->warning("JWT error: Not authorised for donation ID $donationId");

            return false;
        }

        return true;
    }

    #[Pure]
    private static function getSecret(): string
    {
        $secret = getenv('JWT_DONATION_SECRET');

        if (! is_string($secret)) {
            throw new \RuntimeException("JWT_DONATION_SECRET not set in environment");
        }

        return $secret;
    }
}
