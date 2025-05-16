<?php

namespace MatchBot\Application\Actions\RegularGivingMandate;

use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Assertion;
use MatchBot\Domain\DomainException\NonCancellableStatus;
use MatchBot\Domain\MandateCancellationType;
use MatchBot\Domain\RegularGivingMandateRepository;
use MatchBot\Domain\RegularGivingService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Slim\Exception\HttpNotFoundException;

/**
 * Takes a request, with no payload, from BG team to cancel a Mandate. May be because a charity
 * can't accept regular donations any more or occasionally at a donor's request. No detailed
 * reason is collected – we'll use Salesforce Chatter or similar for that as needed.
 */
class CancelAsAdmin extends Action
{
    public function __construct(
        private RegularGivingService $mandateService,
        private RegularGivingMandateRepository $mandateRepository,
        LoggerInterface $logger,
    ) {
        parent::__construct($logger);
    }

    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        Assertion::string($args['mandateId'], 'Expected mandateId to be a string');
        Assertion::uuid($args['mandateId'], 'Expected mandateId to be a valid UUID');
        $mandateId = Uuid::fromString($args['mandateId']);
        $mandate = $this->mandateRepository->findOneByUuid($mandateId);

        if ($mandate === null) {
            throw new HttpNotFoundException($request, 'Mandate not found for UUID ' . $args['mandateId']);
        }

        try {
            $this->mandateService->cancelMandate($mandate, 'Requested in Salesforce', MandateCancellationType::BigGiveCancelled);
        } catch (NonCancellableStatus $e) {
            return $this->validationError(
                response: $response,
                logMessage: $e->getMessage(),
            );
        }

        return new JsonResponse([], 200);
    }
}
