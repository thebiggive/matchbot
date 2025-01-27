<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions;

use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

abstract class Action
{
    /**
     * @param LoggerInterface $logger
     */
    public function __construct(protected readonly LoggerInterface $logger)
    {
    }

    /**
     * @param Request  $request
     * @param Response $response
     * @param array    $args
     * @return Response
     * @throws HttpNotFoundException
     * @throws HttpBadRequestException
     */
    public function __invoke(Request $request, Response $response, $args): Response
    {
        try {
            return $this->action($request, $response, $args);
        } catch (DomainRecordNotFoundException $e) {
            throw new HttpNotFoundException($request, $e->getMessage(), $e);
        }
    }

    /**
     * @return Response
     * @throws DomainRecordNotFoundException
     * @throws HttpBadRequestException
     */
    abstract protected function action(Request $request, Response $response, array $args): Response;

    /**
     * @param  string $name
     * @return mixed
     * @throws HttpBadRequestException
     */
    protected function resolveArg(Request $request, string $name, array $args)
    {
        if (!isset($args[$name])) {
            throw new HttpBadRequestException($request, "Could not resolve argument `{$name}`.");
        }

        return $args[$name];
    }

    /**
     * @param string        $logMessage
     * @param string|null   $publicMessage  Falls back to $logMessage if null.
     * @param bool          $reduceSeverity Whether to log this error only at INFO level. Used to
     *                                      avoid noise from known issues.
     * @return Response with 400 HTTP response code.
     */
    protected function validationError(
        Response $response,
        string $logMessage,
        ?string $publicMessage = null,
        bool $reduceSeverity = false
    ): Response {
        if ($reduceSeverity) {
            $this->logger->info($logMessage);
        } else {
            $this->logger->warning($logMessage);
        }
        $error = new ActionError(ActionError::BAD_REQUEST, $publicMessage ?? $logMessage);

        return $this->respond($response, new ActionPayload(400, null, $error));
    }

    /**
     * @param  array|object|null $data
     * @return Response
     */
    protected function respondWithData(Response $response, $data = null, int $statusCode = 200): Response
    {
        $payload = new ActionPayload($statusCode, $data);
        return $this->respond($response, $payload);
    }

    /**
     * @param ActionPayload $payload
     * @return Response
     */
    protected function respond(Response $response, ActionPayload $payload): Response
    {
        $json = json_encode($payload, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        $response->getBody()->write($json);

        return $response
            ->withStatus($payload->getStatusCode())
            ->withHeader('Content-Type', 'application/json');
    }
}
