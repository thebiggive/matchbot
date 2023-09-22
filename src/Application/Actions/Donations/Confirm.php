<?php

namespace MatchBot\Application\Actions\Donations;

use Doctrine\ORM\EntityManagerInterface;
use http\Exception\BadMethodCallException;
use Laminas\Diactoros\Response\EmptyResponse;
use Laminas\Diactoros\Response\JsonResponse;
use MatchBot\Application\Actions\Action;
use MatchBot\Client\NotFoundException;
use MatchBot\Domain\DonationRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpBadRequestException;
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
     * using the payment method provided. Does not update the donation status, that only gets updated when stripe calls
     * back to say the money is taken.
     */
    protected function action(Request $request, Response $response, array $args): Response
    {
        try {
            $requestBody = json_decode(
                $request->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR
            );
        } catch (\JsonException) {
            throw new HttpBadRequestException($request, 'Cannot parse request body as JSON');
        }
        \assert(is_array($requestBody));

        $pamentMethodId = $requestBody['stripePaymentMethodId'];
        \assert((is_string($pamentMethodId)));

        $this->entityManager->beginTransaction();

        $donation = $this->donationRepository->findAndLockOneBy(['uuid' => $args['donationId']]);
        if (! $donation) {
            throw new NotFoundException();
        }

        $paymentIntentId = $donation->getTransactionId();
        $paymentMethod = $this->stripeClient->paymentMethods->retrieve($pamentMethodId);

        if ($paymentMethod->type !== 'card') {
            throw new HttpBadRequestException($request, 'Confirm endpoint only supports card payments for now');
        }

        // documented at https://stripe.com/docs/api/payment_methods/object?lang=php
        // Contrary to what Stripes docblock says, in my testing 'brand' is strings like 'visa' or 'amex'. Not 'Visa' or
        // 'American Express'
        $cardBrand = $paymentMethod->card->brand;
        \assert(is_string($cardBrand));

        // two letter upper string, e.g. 'GB', 'US'.
        $cardCountry = $paymentMethod->card->country;
        \assert(is_string($cardCountry));

        // at present if the following line was left out we would charge a wrong fee in some cases. I'm not happy with
        // that, would like to find a way to make it so if its left out we get an error instead - either by having
        // derive fees return a value, or making functions like Donation::getCharityFeeGross throw if called before it.
        $this->donationRepository->deriveFees($donation, $cardBrand, $cardCountry);

        $this->stripeClient->paymentIntents->update($paymentIntentId, [
            // only setting things that may need to be updated at this point.
            'metadata' => [
                'stripeFeeRechargeGross' => $donation->getCharityFeeGross(),
                'stripeFeeRechargeNet' => $donation->getCharityFee(),
                'stripeFeeRechargeVat' => $donation->getCharityFeeVat(),
            ],
            // See https://stripe.com/docs/connect/destination-charges#application-fee
            // Update the fee amount in case the final charge was from
            // e.g. a Non EU / Amex card where fees are varied.
            'application_fee_amount' => $donation->getAmountToDeductFractional(),
            // Note that `on_behalf_of` is set up on create and is *not allowed* on update.
        ]);

        $this->stripeClient->paymentIntents->confirm($paymentIntentId, [
            'payment_method' => $pamentMethodId,
        ]);

        $updatedIntent = $this->stripeClient->paymentIntents->retrieve($paymentIntentId);

        $this->entityManager->flush();

        return new JsonResponse([
                'paymentIntent' => [
                    'status' => $updatedIntent->status,
                    'client_secret' => $updatedIntent->client_secret
                ]]
        );
    }
}
