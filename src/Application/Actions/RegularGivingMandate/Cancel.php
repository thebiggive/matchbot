<?php

namespace MatchBot\Application\Actions\RegularGivingMandate;

use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\AssertionFailedException;
use MatchBot\Application\HttpModels\MandateCancellation;
use MatchBot\Application\Security\Security;
use MatchBot\Domain\RegularGivingMandateRepository;
use MatchBot\Domain\RegularGivingService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpUnauthorizedException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Takes a request from the donor to cancel / discontinue their regular giving, stopping future payments. Does not
 * affect payments already taken, and there will be at least one which was collected at mandate creation time.
 */
class Cancel extends Action
{
    public function __construct(
        private RegularGivingService $mandateService,
        private RegularGivingMandateRepository $mandateRepository,
        private SerializerInterface $serializer,
        private Security $securityService,
        LoggerInterface $logger,
    ) {
        parent::__construct($logger);
    }

    protected function action(Request $request, Response $response, array $args): Response
    {
        $authenticatedDonor = $this->securityService->requireAuthenticatedDonorAccountWithPassword($request);
        $body = (string) $request->getBody();

        try {
            $cancellation = $this->serializer->deserialize($body, MandateCancellation::class, 'json');
        } catch (AssertionFailedException | \TypeError | UnexpectedValueException $exception) {
            $message = 'Mandate cancel data deserialise error';
            $exceptionType = get_class($exception);

            return $this->validationError(
                response: $response,
                logMessage: "$message: $exceptionType - {$exception->getMessage()}",
                publicMessage: $message,
            );
        }

        $mandateUUID = $cancellation->mandateUUID;
        if ($args['mandateId'] !== $mandateUUID->toString()) {
            return $this->validationError(
                response: $response,
                logMessage: "Mandate Cancel UUID mismatch {$args['mandateId']} / {$mandateUUID->toString()}}"
            );
        }

        $mandate = $this->mandateRepository->findOneByUuid($mandateUUID);
        if ($mandate === null) {
            throw new HttpNotFoundException($request, 'Mandate not found for UUID ' . $mandateUUID->toString());
        }

        if (! $authenticatedDonor->id()->equals($mandate->donorId())) {
            throw new HttpUnauthorizedException($request, 'Mandate does not below to donor ID ' . $authenticatedDonor->id()->id);
        }

        $this->mandateService->cancelMandate($mandate, $cancellation->cancellationReason);

        return new JsonResponse([], 200);
    }
}
