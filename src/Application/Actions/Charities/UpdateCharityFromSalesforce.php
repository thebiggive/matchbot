<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions\Charities;

use MatchBot\Application\Actions\Action;
use MatchBot\Application\Actions\ActionPayload;
use MatchBot\Application\AssertionFailedException;
use MatchBot\Application\Messenger\CharityUpdated;
use MatchBot\Domain\DomainException\DomainRecordNotFoundException;
use MatchBot\Domain\Salesforce18Id;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class UpdateCharityFromSalesforce extends Action
{
    public function __construct(
        private MessageBusInterface $messageBus,
        LoggerInterface $logger,
    ) {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {
        $salesforceId = $args['salesforceId'] ?? null;

        if (! is_string($salesforceId)) {
            throw new DomainRecordNotFoundException('Missing charity ID');
        }

        try {
            $sfId = Salesforce18Id::ofCharity($salesforceId);
        } catch (AssertionFailedException $e) {
            throw new HttpNotFoundException($request, $e->getMessage());
        }

        $requestTraceId = $_SERVER['HTTP_X_AMZN_TRACE_ID'] ?? null;
        $this->messageBus->dispatch(new Envelope(new CharityUpdated($sfId, $requestTraceId)));

        return $this->respond($response, new ActionPayload(200));
    }
}
