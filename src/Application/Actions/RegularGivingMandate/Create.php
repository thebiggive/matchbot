<?php

namespace MatchBot\Application\Actions\RegularGivingMandate;

use DateTimeInterface;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use MatchBot\Application\Environment;
use MatchBot\Domain\Money;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\RegularGivingMandateRepository;
use MatchBot\Domain\RegularGivingService;
use MatchBot\Domain\Salesforce18Id;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;

class Create extends Action
{
    public function __construct(
        private Environment          $environment,
        LoggerInterface              $logger,
        private RegularGivingService $regularGivingService,
    )
    {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {
        // create donor account if not existing.

        // create pending mandate

        // create first three pending donations for mandate.

        // throw if any donation is not fully matched, (unless the donor has told us that they're OK with making an
        // unmatched or partially matched donation)

        // tell stripe to take payment for first donation. Throw if payment fails synchronously.

        // another class will receive the event from stripe later to say first donation is collected, and
        // then activate the mandate (i.e. update it status using RegularGivingMandate::activate ) and email
        // the donor.

        // Return some details of the pending mandate to FE. FE will poll the mandate for the update to show
        // when the mandate is active.

        return new JsonResponse([]);
    }
}