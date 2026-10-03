<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions;

use Assert\Assertion;
use MatchBot\Client\GenericSalesforceGet;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;

/**
 * Light proxy to Salesforce for home page highlight cards, global stats and fund info. Currently retrieves via
 * CloudFront (`baseUriCached`), so not worrying about local response caching for now.
 */
class SalesforceGetProxy extends Action
{
    public function __construct(
        private GenericSalesforceGet $sfClient,
        LoggerInterface $logger,
    ) {
        parent::__construct($logger);
    }

    #[\Override] protected function action(Request $request, Response $response, array $args): Response
    {
        Assertion::keyExists($args, 'path');
        Assertion::string($args['path']);
        $this->exitIfPathUnexpected($args['path'], $request);

        $responseStream = $this->sfClient->get($args['path']);

        return $response->withBody($responseStream);
    }

    private function exitIfPathUnexpected(string $path, Request $request): void
    {
        $exactPaths = [
            'campaigns/services/apexrest/v1.0/campaigns/stats',
            'campaigns/services/apexrest/v1.0/highlight-service',
        ];

        if (in_array($path, $exactPaths, true)) {
            return;
        }

        if (str_starts_with($path, 'funds/services/apexrest/v1.0/funds/slug/')) {
            return;
        }

        throw new HttpNotFoundException($request, 'Unknown Salesforce proxy path');
    }
}
