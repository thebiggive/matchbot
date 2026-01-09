<?php

declare(strict_types=1);

namespace MatchBot\Application\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use JetBrains\PhpStorm\Pure;
use MatchBot\Domain\PersonId;
use Psr\Log\LoggerInterface;

/**
 * @psalm-type IdentityJWT object{sub: object{person_id: string, psp_id: ?string, complete?: boolean|null}}&\stdClass
 */
final class IdentityTokenService
{
    public function __construct(private string $baseUri)
    {
    }

    /**
     * @link https://stackoverflow.com/questions/39239051/rs256-vs-hs256-whats-the-difference has info on hash
     * algorithm choice. Since we use the secret only server-side and will secure it like other secrets,
     * and symmetric is faster, it's the best and simplest fit for this use case.
     */
    private static string $algorithm = 'HS256';

    /**
     * @return IdentityJWT
     */
    public static function decodeJWT(string $jws): object
    {
        /** @var IdentityJWT $decodedJwtBody */
        $decodedJwtBody = JWT::decode($jws, static::getKey());

        return $decodedJwtBody;
    }

    /**
     * Checks an Identity app token is valid. For the purpose of linking new donations to
     * a Person we don't mind if there's a password so the `complete` claim is not checked.
     *
     * @param string            $personId   UUID for a person
     * @param string            $jws        Compact JWS (signed JWT)
     * @param LoggerInterface   $logger
     * @return bool Whether the token is valid for the given person.
     */
    public function check(?string $personId, string $jws, LoggerInterface $logger): bool
    {
        try {
            $decodedJwtBody = self::decodeJWT($jws);
        } catch (\Exception $exception) {
            $type = get_class($exception);
            // This is only a warning for now. We've seen likely crawlers + bots send invalid
            // requests. In the event that we find they are sending partial JWTs (rather than
            // none) and so getting here we might consider further reducing this log to `info()`
            // level so we can spot more serious issues.
            $logger->warning("JWT error: decoding for person ID $personId: $type - {$exception->getMessage()}");

            return false;
        }

        if ($decodedJwtBody->iss !== $this->baseUri) {
            $logger->error("JWT error: issued by wrong site {$decodedJwtBody->iss}");

            return false;
        }

        if (($personId !== null) && $personId !== $decodedJwtBody->sub->person_id) {
            $logger->warning("JWT error: Not authorised for person ID $personId");

            return false;
        }

        return true;
    }

    public static function getPersonId(string $jws): PersonId
    {
        $decodedJwtBody = self::decodeJWT($jws);
        return PersonId::of($decodedJwtBody->sub->person_id);
    }

    public static function getPspId(string $jws): ?string
    {
        try {
            $decodedJwtBody = self::decodeJWT($jws);
        } catch (\Exception $exception) {
            // Should never happen in practice because we `check()` first.
            return null;
        }

        return $decodedJwtBody->sub->psp_id ?? null;
    }

    private static function getKey(): Key
    {
        return new Key(static::getSecret(), static::$algorithm);
    }

    #[Pure]
    private static function getSecret(): string
    {
        $secret = getenv('JWT_ID_SECRET');

        if (! is_string($secret)) {
            throw new \RuntimeException("JWT_ID_SECRET not set in environment");
        }

        return $secret;
    }

    public function isComplete(?string $jws): bool
    {
        if ($jws === null) {
            return false;
        }

        try {
            $decodedJwtBody = self::decodeJWT($jws);
        } catch (\Exception $exception) {
            // Should never happen in practice because we `check()` first.
            return false;
        }

        return $decodedJwtBody->sub->complete ?? false;
    }
}
