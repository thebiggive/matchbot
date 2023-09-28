<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\Donations\Confirm;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\TestCase;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Slim\Psr7\Response;
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
            'status' => 'final_intent_status',
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
            ]
        );

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

        $sut = new Confirm(
            new NullLogger(),
            $donationRepositoryProphecy->reveal(),
            $stripeClientProphecy->reveal(),
            $this->prophesize(EntityManagerInterface::class)->reveal()
        );

        // act

        $response = $sut(
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

        // assert

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['paymentIntent' => ['status' => 'final_intent_status', 'client_secret' => 'some_client_secret']],
            \json_decode($response->getBody()->getContents(), true)
        );
    }

    /**
     * @return ObjectProphecy<StripeClient>
     */
    public function fakeStripeClient(
        array $cardDetails,
        string $paymentMethodId,
        array $updatedIntentData,
        string $paymentIntentId,
        array $expectedMetadataUpdate
    ): ObjectProphecy {
        $paymentMethod = (object)[
            'type' => 'card',
            'card' => (object)$cardDetails,
        ];
        $stripePaymentMethodsProphecy = $this->prophesize(PaymentMethodService::class);
        $stripePaymentMethodsProphecy->retrieve($paymentMethodId)
            ->willReturn($paymentMethod);

        $stripePaymentIntentsProphecy = $this->prophesize(PaymentIntentService::class);

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->paymentMethods = $stripePaymentMethodsProphecy;
        $stripeClientProphecy->paymentIntents = $stripePaymentIntentsProphecy;

        $stripePaymentIntentsProphecy->retrieve($paymentIntentId)
            ->willReturn((object)$updatedIntentData);

        $stripePaymentIntentsProphecy->update(
            $paymentIntentId,
            $expectedMetadataUpdate
        )->shouldBeCalled();

        $stripePaymentIntentsProphecy->confirm($paymentIntentId, ["payment_method" => $paymentMethodId])
            ->shouldBeCalled();

        return $stripeClientProphecy;
    }
}
