<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use Doctrine\ORM\EntityManagerInterface;
use Laminas\Diactoros\ServerRequest;
use MatchBot\Application\Actions\Donations\Confirm;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Domain\Campaign;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Tests\TestCase;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Slim\Psr7\Response;
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
        $stripeClientProphecy = $this->fakeStripeClient(
            cardDetails: ['brand' => 'acme-payment-cards', 'country' => 'some-country'],
            paymentMethodId: 'PAYMENT_METHOD_ID',
            updatedIntentData: [
            'status' => 'final_intent_status',
            'next_action' => 'some_next_action',
            ],
            paymentIntentId: 'PAYMENT_INTENT_ID',
            expectedMetadataUpdate: [
            "metadata" => [
                "stripeFeeRechargeGross" => "42.00",
                "stripeFeeRechargeNet" => "42.00",
                "stripeFeeRechargeVat" => "0.00"
            ],
            "application_fee_amount" => 4200
            ]
        );

        $donationRepositoryProphecy = $this->prophesize(DonationRepository::class);

        $donation = Donation::fromApiModel(
            new DonationCreate(currencyCode: 'GBP', donationAmount: '63.0', projectId: 'doesnt-matter', psp: 'stripe'),
            new Campaign()
        );
        $donation->setTransactionId('PAYMENT_INTENT_ID');

        $donationRepositoryProphecy->deriveFees($donation, 'acme-payment-cards', 'some-country')
            ->will(
                // in reality the fee would be calculated according to details of the card etc. The Calculator class is
                //tested separately. 42 is just a stub value.
                fn() => $donation->setCharityFee('42.00')
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
            ['paymentIntent' => ['status' => 'final_intent_status', 'next_action' => 'some_next_action']],
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
