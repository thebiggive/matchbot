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
use Slim\Psr7\Stream;

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

        // because we've consumed the body content we now have to put the content back into a new request
        // body so that the next handler can read it. More awkward than I'd like, but followed method from
        // https://evertpot.com/222/
        //
        // This wasn't an issue up till now because all the requests from SF happened to have empty bodies.

        $stream = fopen('php://memory', 'rb+');
        \assert(\is_resource($stream));
        fwrite($stream, $content);
        rewind($stream);

        return $handler->handle($request->withBody(new Stream($stream)));
    }

    protected function unauthorised(LoggerInterface $logger): ResponseInterface
    {
        $logger->warning('Unauthorised');

        /** @var ResponseInterface $response */
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

        return ($givenHash === $expectedHash);
    }
}
