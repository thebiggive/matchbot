<?php

declare(strict_types=1);

namespace MatchBot\Application\Handlers;

use Exception;
use MatchBot\Application\Actions\ActionError;
use MatchBot\Application\Actions\ActionPayload;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpNotImplementedException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Handlers\ErrorHandler as SlimErrorHandler;
use Slim\Interfaces\CallableResolverInterface;
use Throwable;

/**
 * @psalm-suppress PropertyNotSetInConstructor - not possible to set following properties in constructor as they
 * are not known at construction time.
 * {@see SlimErrorHandler::$statusCode}, {@see SlimErrorHandler::$exception}
 */
class HttpErrorHandler extends SlimErrorHandler
{
    public function __construct(
        CallableResolverInterface $callableResolver,
        ResponseFactoryInterface $responseFactory,
        ?LoggerInterface $logger,
        ServerRequestInterface $request,
    ) {
        $this->request = $request;
        $this->logErrors = false;
        parent::__construct($callableResolver, $responseFactory, $logger);
    }

    /**
     * @inheritdoc
     */
    #[\Override]
    protected function respond(): Response
    {
        $exception = $this->exception;
        $statusCode = 500;
        $error = new ActionError(
            ActionError::SERVER_ERROR,
            'An internal error has occurred while processing your request.'
        );

        if ($exception instanceof HttpException) {
            $statusCode = $exception->getCode();
            $error->setDescription($exception->getMessage());

            if ($exception instanceof HttpNotFoundException) {
                $error->setType(ActionError::RESOURCE_NOT_FOUND);
            } elseif ($exception instanceof HttpMethodNotAllowedException) {
                $error->setType(ActionError::NOT_ALLOWED);
            } elseif ($exception instanceof HttpUnauthorizedException) {
                $error->setType(ActionError::UNAUTHENTICATED);
            } elseif ($exception instanceof HttpForbiddenException) {
                $error->setType(ActionError::INSUFFICIENT_PRIVILEGES);
            } elseif ($exception instanceof HttpBadRequestException) {
                $error->setType(ActionError::BAD_REQUEST);
            } elseif ($exception instanceof HttpNotImplementedException) {
                $error->setType(ActionError::NOT_IMPLEMENTED);
            }
        }

        if (
            !($exception instanceof HttpException)
            && $this->displayErrorDetails
        ) {
            $error->setDescription($exception->getMessage());

            $trace = array_map(
                /** @psalm-suppress PossiblyUndefinedArrayOffset
                 * Offsets defined at https://www.php.net/manual/en/exception.gettrace.php
                 */
                fn(array $frame) => "{$frame['class']}::{$frame['function']} {$frame['file']}:{$frame['line']}",
                $exception->getTrace()
            );

            $data = ['error' => $error, 'trace' => $trace];
        } else {
            $data = null;
        }

        $payload = new ActionPayload($statusCode, $data, $error);
        try {
            $encodedPayload = json_encode($payload, JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logger->warning(sprintf(
                'Original error is not JSON so cannot be returned verbatim: %s',
                $exception->getMessage(),
            ));
            $encodedPayload = json_encode(new ActionPayload($statusCode), JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        }

        $response = $this->responseFactory->createResponse($statusCode);
        $response->getBody()->write($encodedPayload);

        if (!($this->exception instanceof HttpException)) {
            $this->logError(sprintf(
                "%s: %s \n#\n %s \n %s",
                get_class($this->exception),
                $this->exception->getMessage(),
                $this->exception->getFile() . ":" . $this->exception->getLine(),
                $this->exception->getTraceAsString(),
            ));
        }

        return $response->withHeader('Content-Type', 'application/json');
    }
}
