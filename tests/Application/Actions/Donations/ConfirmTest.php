<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\Donations\Confirm;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Client\Stripe;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Response;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\UnknownApiErrorException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\Service\PaymentIntentService;
use Stripe\Service\PaymentMethodService;
use Stripe\StripeClient;

class ConfirmTest extends TestCase
{
    public function test_it_confirms_a_card_donation(): void
    {
        // arrange

        // in reality the fee would be calculated according to details of the card etc. The Calculator class is
        //tested separately. This is just a dummy value.
        $newCharityFee = "42.00";
        $newApplicationFeeAmount = 4200;

        $stripeClientProphecy = $this->fakeStripeClient(
            cardDetails: ['brand' => 'discover', 'country' => 'some-country'],
            paymentMethodId: 'PAYMENT_METHOD_ID',
            updatedIntentData: [
                'status' => 'requires_action',
                'client_secret' => 'some_client_secret',
            ],
            paymentIntentId: 'PAYMENT_INTENT_ID',
            expectedMetadataUpdate: [
                "metadata" => [
                    "stripeFeeRechargeGross" => $newCharityFee,
                    "stripeFeeRechargeNet" => $newCharityFee,
                    "stripeFeeRechargeVat" => "0.00",
                ],
                "application_fee_amount" => $newApplicationFeeAmount,
            ],
            confirmFailsWithCardError: false,
            confirmFailsWithApiError: false,
            confirmFailsWithPaymentMethodUsedError: false,
        );

        // Make sure the latest fees, based on card type, are saved to the database.
        $em = $this->prophesize(EntityManagerInterface::class);
        $em->beginTransaction()->shouldBeCalledOnce();
        $em->flush()->shouldBeCalledOnce();
        $em->commit()->shouldBeCalledOnce();

        $sut = new Confirm(
            new NullLogger(),
            $this->getDonationRepository($newCharityFee),
            $stripeClientProphecy->reveal(),
            $em->reveal(),
        );

        // act
        $response = $this->callConfirm($sut);

        // assert

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['paymentIntent' => ['status' => 'requires_action', 'client_secret' => 'some_client_secret']],
            \json_decode($response->getBody()->getContents(), true)
        );
    }

    public function testItReturns402OnDecline(): void
    {
        // arrange

        // in reality the fee would be calculated according to details of the card etc. The Calculator class is
        //tested separately. This is just a dummy value.
        $newCharityFee = "42.00";
        $newApplicationFeeAmount = 4200;

        $stripeClientProphecy = $this->fakeStripeClient(
            cardDetails: ['brand' => 'discover', 'country' => 'some-country'],
            paymentMethodId: 'PAYMENT_METHOD_ID',
            updatedIntentData: [
                'status' => 'requires_payment_method',
                'client_secret' => 'some_client_secret',
            ],
            paymentIntentId: 'PAYMENT_INTENT_ID',
            expectedMetadataUpdate: [
                "metadata" => [
                    "stripeFeeRechargeGross" => $newCharityFee,
                    "stripeFeeRechargeNet" => $newCharityFee,
                    "stripeFeeRechargeVat" => "0.00",
                ],
                "application_fee_amount" => $newApplicationFeeAmount,
            ],
            confirmFailsWithCardError: true,
            confirmFailsWithApiError: false,
            confirmFailsWithPaymentMethodUsedError: false,
        );

        $sut = new Confirm(
            new NullLogger(),
            $this->getDonationRepository($newCharityFee),
            $stripeClientProphecy->reveal(),
            $this->prophesize(EntityManagerInterface::class)->reveal()
        );

        // act
        $response = $this->callConfirm($sut);

        // assert

        $this->assertSame(402, $response->getStatusCode()); // 'Payment required'.
        $this->assertSame(
            ['error' => [
                'message' => 'Your card was declined',
                'code' => 'card_declined',
                'decline_code' => 'generic_decline',
            ]],
            \json_decode($response->getBody()->getContents(), true)
        );
    }

    /**
     * We've seen card test bots, and no humans, try to do this as of Oct '23. For now we want to log it
     * as a warning, so we can see frequency on a dashboard but don't get alarms.
     */
    public function testItReturns402OnStalePaymentMethodReuse(): void
    {
        // arrange

        // in reality the fee would be calculated according to details of the card etc. The Calculator class is
        //tested separately. This is just a dummy value.
        $newCharityFee = "42.00";
        $newApplicationFeeAmount = 4200;

        $stripeClientProphecy = $this->fakeStripeClient(
            cardDetails: ['brand' => 'discover', 'country' => 'some-country'],
            paymentMethodId: 'PAYMENT_METHOD_ID',
            updatedIntentData: [
                'status' => 'requires_payment_method',
                'client_secret' => 'some_client_secret',
            ],
            paymentIntentId: 'PAYMENT_INTENT_ID',
            expectedMetadataUpdate: [
                "metadata" => [
                    "stripeFeeRechargeGross" => $newCharityFee,
                    "stripeFeeRechargeNet" => $newCharityFee,
                    "stripeFeeRechargeVat" => "0.00",
                ],
                "application_fee_amount" => $newApplicationFeeAmount,
            ],
            confirmFailsWithCardError: false,
            confirmFailsWithApiError: false,
            confirmFailsWithPaymentMethodUsedError: true,
        );

        $sut = new Confirm(
            new NullLogger(),
            $this->getDonationRepository($newCharityFee),
            $stripeClientProphecy->reveal(),
            $this->prophesize(EntityManagerInterface::class)->reveal()
        );

        // act
        $response = $this->callConfirm($sut);

        // assert

        $this->assertSame(402, $response->getStatusCode()); // 'Payment required'.
        $this->assertSame(
            ['error' => [
                'message' => 'Payment method cannot be used again',
                'code' => 'invalid_request_error',
            ]],
            \json_decode($response->getBody()->getContents(), true)
        );
    }

    public function testItReturns500OnApiError(): void
    {
        // arrange

        // in reality the fee would be calculated according to details of the card etc. The Calculator class is
        //tested separately. This is just a dummy value.
        $newCharityFee = "42.00";
        $newApplicationFeeAmount = 4200;

        $stripeClientProphecy = $this->fakeStripeClient(
            cardDetails: ['brand' => 'discover', 'country' => 'some-country'],
            paymentMethodId: 'PAYMENT_METHOD_ID',
            updatedIntentData: [
                'status' => 'requires_payment_method',
                'client_secret' => 'some_client_secret',
            ],
            paymentIntentId: 'PAYMENT_INTENT_ID',
            expectedMetadataUpdate: [
                "metadata" => [
                    "stripeFeeRechargeGross" => $newCharityFee,
                    "stripeFeeRechargeNet" => $newCharityFee,
                    "stripeFeeRechargeVat" => "0.00",
                ],
                "application_fee_amount" => $newApplicationFeeAmount,
            ],
            confirmFailsWithCardError: false,
            confirmFailsWithApiError: true,
            confirmFailsWithPaymentMethodUsedError: false,
        );

        $sut = new Confirm(
            new NullLogger(),
            $this->getDonationRepository($newCharityFee),
            $stripeClientProphecy->reveal(),
            $this->prophesize(EntityManagerInterface::class)->reveal()
        );

        // act
        $response = $this->callConfirm($sut);

        // assert

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(
            ['error' => [
                'message' => 'Stripe is down!',
                'code' => 'some_stripe_anomaly',
            ]],
            \json_decode($response->getBody()->getContents(), true)
        );
    }

    /**
     * @return ObjectProphecy<Stripe>
     */
    private function fakeStripeClient(
        array $cardDetails,
        string $paymentMethodId,
        array $updatedIntentData,
        string $paymentIntentId,
        array $expectedMetadataUpdate,
        bool $confirmFailsWithCardError,
        bool $confirmFailsWithApiError,
        bool $confirmFailsWithPaymentMethodUsedError,
    ): ObjectProphecy {
        $paymentMethod = new PaymentMethod(['id' => 'id-doesnt-matter-for-test']);
        $paymentMethod->type = 'card';

        /** @psalm-suppress InvalidPropertyAssignmentValue */
        $paymentMethod->card = $cardDetails;
        $stripeProphecy = $this->prophesize(Stripe::class);
        $stripeProphecy->retrievePaymentMethod($paymentMethodId)
            ->willReturn($paymentMethod);

        $updatedPaymentIntent = new PaymentIntent(['id' => 'id-doesnt-matter-for-test', ...$updatedIntentData]);
        $updatedPaymentIntent->status = $updatedIntentData['status'];
        $updatedPaymentIntent->client_secret = $updatedIntentData['client_secret']; // here

        $stripeProphecy->retrievePaymentIntent($paymentIntentId)
            ->willReturn($updatedPaymentIntent);

        $stripeProphecy->updatePaymentIntent(
            $paymentIntentId,
            $expectedMetadataUpdate
        )->shouldBeCalled();

        $confirmation = $stripeProphecy->confirmPaymentIntent($paymentIntentId, ["payment_method" => $paymentMethodId])
            ->willReturn($updatedPaymentIntent);

        if ($confirmFailsWithCardError) {
            $exception = CardException::factory(
                message: 'Your card was declined',
                httpStatus: 402,
                stripeCode: 'card_declined',
                declineCode: 'generic_decline',
            );
            $confirmation->willThrow($exception);
        }

        if ($confirmFailsWithPaymentMethodUsedError) {
            $exception = InvalidRequestException::factory(
                'The provided PaymentMethod was previously used with a PaymentIntent without Customer attachment...',
                httpStatus: 402,
                stripeCode: 'invalid_request_error',
            );
            $confirmation->willThrow($exception);
        }

        if ($confirmFailsWithApiError) {
            $exception = UnknownApiErrorException::factory(
                'Stripe is down!',
                httpStatus: 500,
                stripeCode: 'some_stripe_anomaly'
            );
            $confirmation->willThrow($exception);
        }

        $confirmation->shouldBeCalled();

        return $stripeProphecy;
    }

    /**
     * @return DonationRepository Really an ObjectProphecy<DonationRepository>, but psalm
     *                            complains about that.
     */
    private function getDonationRepository(string $newCharityFee): DonationRepository
    {
        $donationRepositoryProphecy = $this->prophesize(DonationRepository::class);

        $donation = Donation::fromApiModel(
            new DonationCreate(currencyCode: 'GBP', donationAmount: '63.0', projectId: 'doesnt-matter', psp: 'stripe'),
            $this->getMinimalCampaign(),
        );
        $donation->setTransactionId('PAYMENT_INTENT_ID');

        $donationRepositoryProphecy->deriveFees($donation, 'discover', 'some-country')
            ->will(
                fn() => $donation->setCharityFee($newCharityFee)
            );

        $donationRepositoryProphecy->findAndLockOneBy(['uuid' => 'DONATION_ID'])->willReturn(
            $donation
        );

        $donationRepositoryProphecy->push($donation, false)->willReturn(true);

        return $donationRepositoryProphecy->reveal();
    }

    private function callConfirm(Confirm $sut): ResponseInterface
    {
        return $sut(
            $this->createRequest(
                method: 'POST',
                path: 'doesnt-matter-for-test',
                bodyString: \json_encode([
                    'stripePaymentMethodId' => 'PAYMENT_METHOD_ID',
                ])
            ),
            new Response(),
            ['donationId' => 'DONATION_ID']
        );
    }
}
