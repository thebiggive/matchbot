<?php

declare(strict_types=1);

namespace MatchBot\Application\Auth;

use Fig\Http\Message\StatusCodeInterface;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Assertion;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response;
use Symfony\Component\Clock\ClockInterface;

/**
 * This middleware requires that a request is from our own Salesforce instance, based on a shared secret. Salesforce
 * is a source of truth for much of our data so requests from it may be trusted with wide powers to modify matchbot
 * state.
 *
 * Based on \BigGive\Identity\Client\Mailer
 */
readonly class SalesforceAuthMiddleware implements MiddlewareInterface
{
    public const string HEADER_NAME = 'x-send-verify-hash';

    #[Pure]
    public function __construct(
        /** @var non-empty-string $sfApiKey */
        #[\SensitiveParameter]
        private string $sfApiKey,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
        Assertion::notEmpty($this->sfApiKey);
    }

    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $givenHash = $request->getHeaderLine(self::HEADER_NAME);
        $content = $request->getBody()->getContents();

        if (!$this->verify($givenHash, $content)) {
            return $this->unauthorised($this->logger);
        }

        // Necessary because getContents() has consumed the stream.
        $request->getBody()->rewind();

        return $handler->handle($request);
    }

    protected function unauthorised(LoggerInterface $logger): ResponseInterface
    {
        $logger->warning('Unauthorised');

        $response = new Response(StatusCodeInterface::STATUS_UNAUTHORIZED);
        $response->getBody()->write(json_encode(['error' => 'Unauthorized'], JSON_THROW_ON_ERROR));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function verify(string $givenHash, string $content): bool
    {
        $expectedHash = hash_hmac(
            'sha256',
            trim($content),
            $this->sfApiKey
        );

        if ($givenHash !== $expectedHash) {
            return false;
        }

        if (\json_validate($content)) {
            $asArray = \json_decode($content, true, flags: JSON_THROW_ON_ERROR);
            \assert(\is_array($asArray));
            /** @var int|null $contentTimestamp */
            $contentTimestamp =  $asArray['timestamp'] ?? null;
            Assertion::nullOrNumeric($contentTimestamp);

            $currentTimestamp = $this->clock->now()->getTimestamp();
            if (\is_int($contentTimestamp) && \abs($contentTimestamp - $currentTimestamp) > 120) {
                return false;
            }
        }

        return true;
    }
}
