<?php

namespace MatchBot\Application\Actions;

use Fig\Http\Message\StatusCodeInterface;
use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Slim\Exception\HttpBadRequestException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

class CreateUpdatePaymentMethodIntent extends Action
{
    #[Pure]
    public function __construct(
        private StripeClient $stripeClient,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        $customerId = $request->getAttribute(PersonManagementAuthMiddleware::PSP_ATTRIBUTE_NAME);
        \assert(is_string($customerId));

        try {
            $requestBody = json_decode(
                $request->getBody()->getContents(),
                true,
                512,
                \JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            throw new HttpBadRequestException($request, 'Cannot parse request body as JSON');
        }
        \assert(is_array($requestBody));

        $paymentMethodId = $requestBody['payment_method_id'];
        \assert(is_string($paymentMethodId));

        $setupIntent = $this->stripeClient->setupIntents->create([
            'customer' => $customerId,
            'payment_method' => $paymentMethodId,
            ]);

        return $this->respondWithData($response, data: [
            'hello' => 'world',
            'pm_id' => $paymentMethodId,
            'setupIntent_id' => $setupIntent->id,
        ], statusCode: StatusCodeInterface::STATUS_CREATED);
    }
}
