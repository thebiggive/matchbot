<?php

declare(strict_types=1);

namespace MatchBot\Application\Auth;

use JetBrains\PhpStorm\Pure;
use MatchBot\Application\HttpModels;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use ReCaptcha\ReCaptcha;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\SerializerInterface;

class DonationRecaptchaMiddleware implements MiddlewareInterface
{
    use ErrorTrait;

    #[Pure]
    public function __construct(
        private LoggerInterface $logger,
        private ReCaptcha $captcha,
        private SerializerInterface $serializer,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $timesToAttemptCaptchaVerification = 2;
        $response = null;

        for ($counter = 0; $counter < $timesToAttemptCaptchaVerification; $counter++) {
            $captchaCode = '';

            $body = (string) $request->getBody();

            /** @var HttpModels\Donation $donationData */
            try {
                $donationData = $this->serializer->deserialize(
                    $body,
                    HttpModels\Donation::class,
                    'json'
                );
                $captchaCode = $donationData->creationRecaptchaCode ?? '';
            } catch (UnexpectedValueException $exception) { // This is the Serializer one, not the global one
                // No-op. Allow verification with blank string to occur. This will fail with the live
                // service, but can be mocked with success in unit tests so we can test handling of other
                // code that might need to handle deserialise errors.
            }

            $result = $this->captcha->verify(
                $captchaCode,
                $request->getAttribute('client-ip') // Set to original IP by previous middleware
            );

            if ($result->isSuccess()) {
                $response = $handler->handle($request);
                return $response;
            }

            $this->logger->info('Security: captcha failed, attempt: ' . ($counter + 1));

            if ($counter == ($timesToAttemptCaptchaVerification - 1)) {
                $this->logger->warn('Warning: captcha verification has now failed after ' . $timesToAttemptCaptchaVerification . ' attempts!');
                $this->unauthorised($this->logger, true, $request);
            }
        }
    }
}
