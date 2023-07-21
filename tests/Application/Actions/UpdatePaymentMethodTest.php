<?php

namespace MatchBot\Tests\Application\Actions;

use Laminas\Diactoros\ServerRequest;
use MatchBot\Application\Actions\DeletePaymentMethod;
use MatchBot\Application\Actions\UpdatePaymentMethod;
use MatchBot\Application\Auth\PersonWithPasswordAuthMiddleware;
use MatchBot\Tests\TestCase;
use PHPUnit\Framework\MockObject\Stub;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Slim\Psr7\Response;
use Stripe\Collection;
use Stripe\Service\CustomerService;
use Stripe\Service\PaymentMethodService;
use Stripe\StripeClient;

/**
 * A lot of this is copied from the DeletePaymentMethodTest - consider refactoring before making a third copy.
 */ class UpdatePaymentMethodTest extends TestCase
{
    public function testItUpdatesAPaymentMethod(): void
    {
        // arrange
        $stripePaymentMethodServiceProphecy = $this->prophesize(PaymentMethodService::class);
        $stripeCustomerServiceProphecy = $this->prophesize(CustomerService::class);
        $fakeStripeClient = $this->fakeStripeClient($stripePaymentMethodServiceProphecy, $stripeCustomerServiceProphecy);

        $stripeCustomerServiceProphecy->allPaymentMethods('stripe_customer_id_12')->willReturn(
            $this->stubCollectionOf([
                ['id' => 'stripe_payment_method_id_35']
            ])
        );

        $sut = new UpdatePaymentMethod($fakeStripeClient, new NullLogger());

        $updatedBillingDetails = [
            'billing_details' => [
            ],
            'card' => [
                'exp_month' => '02',
                'exp_year' => '2040',
            ],
        ];

        $request = $this->createRequest('PUT', '/', \json_encode($updatedBillingDetails))
            ->withAttribute(PersonWithPasswordAuthMiddleware::PSP_ATTRIBUTE_NAME, 'stripe_customer_id_12');

        // assert
        $stripePaymentMethodServiceProphecy->update('stripe_payment_method_id_35', $updatedBillingDetails)
            ->shouldBeCalledOnce();

        // act
        $sut->__invoke($request, new Response(), ['payment_method_id' => 'stripe_payment_method_id_35']);
    }

    public function testItRefusesToToUpdatePaymentMethodThatDoesNotBelongToRquester(): void
    {
        $stripePaymentMethodServiceProphecy = $this->prophesize(PaymentMethodService::class);
        $stripeCustomerServiceProphecy = $this->prophesize(CustomerService::class);
        $fakeStripeClient = $this->fakeStripeClient($stripePaymentMethodServiceProphecy, $stripeCustomerServiceProphecy);

        $stripeCustomerServiceProphecy->allPaymentMethods('stripe_customer_id_12')->willReturn(
            $this->stubCollectionOf([
                ['id' => 'different_method_id']
            ])
        );

        $sut = new DeletePaymentMethod($fakeStripeClient, new NullLogger());

        $request = (new ServerRequest())
            ->withAttribute(PersonWithPasswordAuthMiddleware::PSP_ATTRIBUTE_NAME, 'stripe_customer_id_12');

        // assert
        $stripePaymentMethodServiceProphecy->detach(Argument::any())->shouldNotBeCalled();

        // act
        $sut->__invoke($request, new Response(), ['payment_method_id' => 'stripe_payment_method_id_35']);
    }

    public function stubCollectionOf(array $paymentMethods): Stub&Collection
    {
        $stubCollection = $this->createStub(Collection::class);
        $stubCollection->method('toArray')->willReturn(['data' => $paymentMethods]);

        return $stubCollection;
    }

    /**
     * @psalm-suppress UndefinedPropertyAssignment
     * Not sure why Psalm isn't reading the `@property` annotation on the StripeClient class
     */
    public function fakeStripeClient(
        ObjectProphecy $stripePaymentMethodServiceProphecy,
        ObjectProphecy $stripeCustomerServiceProphecy
    ): StripeClient {
        $fakeStripeClient = $this->createStub(StripeClient::class);
        $fakeStripeClient->paymentMethods = $stripePaymentMethodServiceProphecy->reveal();
        $fakeStripeClient->customers = $stripeCustomerServiceProphecy->reveal();

        return $fakeStripeClient;
    }
}
