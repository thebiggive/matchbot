<?php

namespace MatchBot\Application\Actions\RegularGivingMandate;

use Doctrine\ORM\EntityManagerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Application\Environment;
use MatchBot\Application\Security\Security;
use MatchBot\Client\Stripe;
use MatchBot\Domain\StripePaymentMethodId;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;
use Stripe\Exception\InvalidRequestException;

class UpdatePaymentMethod extends Action
{
    public function __construct(
        private Environment $environment,
        private EntityManagerInterface $entityManager,
        private Security $security,
        private Stripe $stripe,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

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

        $previousPaymentMethodId = $donor->getRegularGivingPaymentMethod();
        if ($previousPaymentMethodId) {
            $this->stripe->detatchPaymentMethod($previousPaymentMethodId);
        }

        try {
            $paymentMethod = $this->stripe->retrievePaymentMethod($donor->stripeCustomerId, $methodId);
        } catch (InvalidRequestException $e) {
            throw new HttpBadRequestException($request, 'Could not load new payment method:' . $e->getMessage());
        }

        $donor->setRegularGivingPaymentMethod($methodId);

        $this->entityManager->flush();

        return new JsonResponse(['paymentMethod' => $paymentMethod->toArray()]);
    }
}
