<?php

namespace MatchBot\Application\Actions\Donations;

use Doctrine\ORM\EntityManagerInterface;
use Laminas\Diactoros\Response\EmptyResponse;
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

        $requestBody = json_decode(json: $request->getBody()->getContents(), associative: true, flags: JSON_THROW_ON_ERROR);
        \assert(is_array($requestBody));

        $pamentMethodId = $requestBody['stripePaymentMethodId'];
        \assert((is_string($pamentMethodId)));

        $this->entityManager->beginTransaction();

        $donation = $this->donationRepository->findAndLockOneBy(['uuid' => $args['donationId']]);
        if (! $donation) {
            throw new NotFoundException();
        }

        $paymentIntent = $this->stripeClient->paymentIntents->retrieve($donation->getTransactionId());
        $paymentMethod = $this->stripeClient->paymentMethods->retrieve($pamentMethodId);

        // todo - use paymentMethod object to get card type and country, derive fees on donation and apply those fees
        // before or when confirming the payment.

        // documented at https://stripe.com/docs/api/payment_methods/object?lang=php
        $cardBrand = $paymentMethod->card->brand;
        $cardCountry = $paymentMethod->card->country;

        // at present if the following line was left out we would charge a wrong fee in some cases. I'm not happy with
        // that, would like to find a way to make it so if its left out we get an error instead - either by having
        // derive fees return a value, or making functions like Donation::getCharityFeeGross throw if called before it.
        $this->donationRepository->deriveFees($donation, $cardBrand, $cardCountry);

        $this->stripeClient->paymentIntents->update($donation->getTransactionId(), [
            // only setting things that may need to be updated at this point.
            'metadata' => [
                'stripeFeeRechargeGross' => $donation->getCharityFeeGross(),
                'stripeFeeRechargeNet' => $donation->getCharityFee(),
                'stripeFeeRechargeVat' => $donation->getCharityFeeVat(),
            ],
            // See https://stripe.com/docs/connect/destination-charges#application-fee
            // Update the fee amount incase the final charge was from
            // a Non EU / Amex card where fees are varied.
            'application_fee_amount' => $donation->getAmountToDeductFractional(),
            // Note that `on_behalf_of` is set up on create and is *not allowed* on update.
        ]);

        $paymentIntent->confirm([
            'payment_method' => $paymentMethod->id,
        ]);

        $updatedIntent = $this->stripeClient->paymentIntents->retrieve($donation->getTransactionId());

        $this->entityManager->flush();

        return new JsonResponse([
                'paymentIntent' => [
                    'status' => $updatedIntent->status,
                    'next_action' => $updatedIntent->next_action
                ]]
        );
    }
}
