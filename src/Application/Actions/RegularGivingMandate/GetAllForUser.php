<?php

namespace MatchBot\Application\Actions\RegularGivingMandate;

use MatchBot\Application\Actions\Action;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use MatchBot\Application\Environment;
use MatchBot\Domain\PersonId;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;

class GetAllForUser extends Action {

    public function __construct(
        private Environment $environment,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }
    protected function action(Request $request, Response $response, array $args): Response
    {
        if (! $this->environment->isFeatureEnabledRegularGiving()) {
            throw new HttpNotFoundException($request);
        }

        $donorId = $request->getAttribute(PersonWithPasswordAuthMiddleware::PERSON_ID_ATTRIBUTE_NAME);
        \assert($donorId instanceof PersonId);

        return $this->respondWithData($response, ['mandates' => [
            [
                'donorId' => $donorId->value
                // todo fill in other details of dummy regular giving mandate, then switch
                // to loading from actual db via Doctrine
            ]
        ]]);
    }
}