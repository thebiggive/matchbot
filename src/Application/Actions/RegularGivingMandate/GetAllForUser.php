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

class GetAllForUser extends Action
{
    public function __construct(
        private Environment $environment,
        LoggerInterface $logger,
        private RegularGivingService $regularGivingService,
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

        $mandates = $this->regularGivingService->allActiveForDonorAsApiModel($donorId);

        return new JsonResponse([
            'mandates' => $mandates
        ]);
    }
}
