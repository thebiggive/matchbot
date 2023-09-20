<?php

namespace MatchBot\Application\Actions\Donations;

use Doctrine\ORM\EntityManagerInterface;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\DonationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Stripe\StripeClient;

class Confirm extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private DonationRepository $donationRepository,
        private StripeClient $stripeClient,
        private EntityManagerInterface $entityManager,
    ) {
        parent::__construct($logger);
    }

    /**
     * Called to confirm that the donor wishes to make a donation immediately. We will tell Stripe to take their money
     * using the payment method provided.
     */
    protected function action(Request $request, Response $response, array $args): Response
    {
        // todo - harden code - make sure we bail out early if any needed data is missing or doesn't make sense.

        // todo - add tests. Might have done test-first, but was copying JS code from
        // https://stripe.com/docs/payments/finalize-payments-on-the-server?platform=web&type=payment and translating to PHP.

        $requestBody = json_decode(json: $request->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);

        $pamentMethodId = $requestBody['stripePaymentMethodId'];

        $this->entityManager->beginTransaction();

        $donation = $this->donationRepository->findAndLockOneBy(['uuid' => $args['donationId']]);
        if (! $donation) {
            throw new NotFoundException();
        }

        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($donation->getTransactionId());
        $paymentMethod = $this->stripeClient->paymentMethods->retrieve($pamentMethodId);

        // todo - use paymentMethod object to get card type and country, derive fees on donation and apply those fees
        // before or when confirming the payment.

        $paymentIntent->confirm([
            'payment_method' => $paymentMethod->id,
        ]);

        $this->logger->info("Confirmed donation " . $donation->getUuid() . " using payment method id " . $paymentMethod->id);

        return new JsonResponse([]);
    }
}
