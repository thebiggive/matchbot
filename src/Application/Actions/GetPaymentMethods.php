<?php

declare(strict_types=1);

namespace MatchBot\Application\Actions;

use JetBrains\PhpStorm\Pure;
use MatchBot\Application\Auth\PersonManagementAuthMiddleware;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use MatchBot\Application\Security\Security;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;

class GetPaymentMethods extends Action
{
    #[Pure]
    public function __construct(
        private StripeClient $stripeClient,
        private Security $securityService,
        LoggerInterface $logger
    ) {
        parent::__construct($logger);
    }

    /**
     * @see PersonWithPasswordAuthMiddleware
     */
    #[\Override]
    protected function action(Request $request, Response $response, array $args): Response
    {
        $donor = $this->securityService->requireAuthenticatedDonorAccountWithPassword($request);

        $paymentMethods = $this->stripeClient->customers->allPaymentMethods(
            $donor->stripeCustomerId->stripeCustomerId,
            ['type' => 'card'],
        );

        $paymentMethodArray = $paymentMethods->toArray()['data'];
        \assert(is_array($paymentMethodArray));

        $regularGivingPaymentMethod = null;

        $nonRegularGivingMethods = array_values(array_filter(
            $paymentMethodArray,
            static function (array $paymentMethod) use ($donor, &$regularGivingPaymentMethod): bool {
                if ($paymentMethod['id'] === $donor->getRegularGivingPaymentMethod()?->stripePaymentMethodId) {
                    $regularGivingPaymentMethod = $paymentMethod;
                    return false;
                }

                return true;
            }
        ));

        return $this->respondWithData(
            $response,
            [
                'data' => $nonRegularGivingMethods,
                'regularGivingPaymentMethod' => $regularGivingPaymentMethod
            ]
        );
    }
}
