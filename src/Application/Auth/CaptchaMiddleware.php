<?php

declare(strict_types=1);

namespace MatchBot\Application\Auth;

use JetBrains\PhpStorm\Pure;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Slim\Exception\HttpUnauthorizedException;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * @psalm-suppress UnusedClass - will be used soon
 */
class CaptchaMiddleware implements MiddlewareInterface
{
    #[Pure]
    public function __construct(
        private readonly LoggerInterface $logger,
        protected SerializerInterface $serializer,
        private FriendlyCaptchaVerifier $friendlyCaptchaVerifier,
        private bool $bypassCaptcha = false,
    ) {
    }

    /**
     * @throws \Slim\Exception\HttpUnauthorizedException on verification errors.
     */
    #[\Override]
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->bypassCaptcha) {
            $this->logger->warning('Captcha verification bypassed');
            return $handler->handle($request);
        }

        $captchaCode = $this->getCode($request);

        if ($captchaCode === false) {
            $this->logger->log(LogLevel::WARNING, 'Security: captcha code not sent');
            $this->unauthorised($this->logger, true, $request);
        }

        if (!$this->friendlyCaptchaVerifier->verify($captchaCode)) {
            $this->unauthorised($this->logger, true, $request);
        }

        return $handler->handle($request);
    }

    protected function unauthorised(LoggerInterface $logger, bool $likelyBot, ServerRequestInterface $request): never
    {
        if ($likelyBot) {
            // We've seen traffic with no JWTs from crawlers etc. before so don't
            // want to log this as a warning.
            $logger->info('Unauthorised – following bot-like patterns');
        } else {
            $logger->warning('Unauthorised');
        }

        throw new HttpUnauthorizedException($request, 'Unauthorised');
    }

    private function getCode(ServerRequestInterface $request): false|string
    {
        $captchaHeaders = $request->getHeader('x-captcha-code');

        return reset($captchaHeaders);
    }
}
