<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions;

use Assert\Assertion;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackHeaderBlock;
use Symfony\Component\Notifier\Bridge\Slack\Block\SlackSectionBlock;
use Symfony\Component\Notifier\Bridge\Slack\SlackOptions;
use Symfony\Component\Notifier\Message\ChatMessage;

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
     * @param array<string, string|null> $args
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
     * @param array<string, string|null> $args
     *
     * @return Response
     * @throws DomainRecordNotFoundException
     * @throws HttpBadRequestException
     */
    abstract protected function action(Request $request, Response $response, array $args): Response;

    public function prepareSlackMessage(string $heading, string $body): ChatMessage
    {
        return new ChatMessage(
            subject: $heading,
            options: (new SlackOptions())
                ->block((new SlackHeaderBlock(sprintf(
                    '[%s] %s',
                    (string)getenv('APP_ENV'),
                    $heading,
                ))))
                ->block((new SlackSectionBlock())->text($body))
                ->iconEmoji(':o')
        );
    }

    /**
     * @param array<mixed> $args
     */
    protected function argToUuid(array $args, string $argName): UuidInterface
    {
        Assertion::keyExists($args, $argName);
        $donationUUID = $args[$argName];
        Assertion::string($donationUUID);
        if ($donationUUID === '') {
            throw new DomainRecordNotFoundException("Missing $argName");
        }

        return Uuid::fromString($donationUUID);
    }

    /**
     * @param  string $name
     * @param array<mixed> $args
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
     * @param ActionError::* $errorType Identifier for the type of error to be used by FE.
     * @param array<string, mixed> $errorData JSON-serializable detailed error data for use in FE.
     * @return Response with 400 HTTP response code.
     */
    protected function validationError(
        Response $response,
        string $logMessage,
        ?string $publicMessage = null,
        bool $reduceSeverity = false,
        string $errorType = ActionError::BAD_REQUEST,
        array $errorData = [],
    ): Response {
        if ($reduceSeverity) {
            $this->logger->info($logMessage);
        } else {
            $this->logger->warning($logMessage);
        }
        $error = new ActionError($errorType, $publicMessage ?? $logMessage, $errorData);

        return $this->respond($response, new ActionPayload(400, null, $error));
    }

    /**
     * @param  array<mixed>|object|null $data
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
