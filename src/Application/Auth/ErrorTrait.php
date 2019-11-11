<?php

declare(strict_types=1);

namespace MatchBot\Application\Auth;

use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response;

/**
 * Easily return well-formatted 401s
 */
trait ErrorTrait
{
    protected function unauthorised(LoggerInterface $logger): ResponseInterface
    {
        $logger->warning('Unauthorised');

        /** @var ResponseInterface $response */
        $response = new Response(StatusCodeInterface::STATUS_UNAUTHORIZED);
        $response->getBody()->write(json_encode(['error' => 'Unauthorized'], JSON_PRETTY_PRINT));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
