<?php

namespace MatchBot\Application\Actions\RegularGivingMandate;

use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Environment;
use MatchBot\Application\Security\Security;
use MatchBot\Domain\RegularGivingService;
use MatchBot\Domain\StripePaymentMethodId;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

class UpdatePaymentMethod extends Action
{
    public function __construct(
        private Environment $environment,
        private Security $security,
        LoggerInterface $logger,
        private RegularGivingService $regularGivingService,
    ) {
        parent::__construct($logger);
    }

    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        if (! $this->environment->isFeatureEnabledRegularGiving()) {
            throw new HttpNotFoundException($request);
        }

        $donor = $this->security->requireAuthenticatedDonorAccountWithPassword($request);

        try {
            $requestBody = json_decode(
                $request->getBody()->getContents(),
                true,
                512,
                \JSON_THROW_ON_ERROR
            );
            \assert(is_array($requestBody));
        } catch (\JsonException) {
            throw new HttpBadRequestException($request, 'Cannot parse request body as JSON');
        }

        $paymentMethodId = $requestBody['paymentMethodId']
            ?? throw new HttpBadRequestException($request, 'Missing payment method id');
        \assert(is_string($paymentMethodId));

        $methodId = StripePaymentMethodId::of($paymentMethodId);

        $paymentMethod = $this->regularGivingService->changeDonorRegularGivingPaymentMethod($donor, $methodId);

        return new JsonResponse(['paymentMethod' => $paymentMethod->toArray()]);
    }
}
