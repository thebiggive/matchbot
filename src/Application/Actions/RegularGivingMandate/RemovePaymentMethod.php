<?php

namespace MatchBot\Application\Actions\RegularGivingMandate;

use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Environment;
use MatchBot\Application\Security\Security;
use MatchBot\Client\BadRequestException;
use MatchBot\Domain\DomainException\BadCommandException;
use MatchBot\Domain\RegularGivingService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;

class RemovePaymentMethod extends Action {
    public function __construct(
        private Security $security,
        LoggerInterface $logger,
        private RegularGivingService $regularGivingService,
    ) {
        parent::__construct($logger);
    }
    protected function action(Request $request, Response $response, array $args): Response
    {
        $donor = $this->security->requireAuthenticatedDonorAccountWithPassword($request);

        try {
            $this->regularGivingService->removeDonorRegularGivingPaymentMethod($donor);
        } catch (BadCommandException $e) {
            return $this->validationError(
                response: $response,
                logMessage: $e->__toString(),
                publicMessage: $e->getMessage(),
            );
        }

        return new JsonResponse([]);
    }
}