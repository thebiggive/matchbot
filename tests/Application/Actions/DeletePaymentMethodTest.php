<?php

namespace MatchBot\Tests\Application;

use Laminas\Diactoros\ServerRequest;
use MatchBot\Application\Actions\DeletePaymentMethod;
use MatchBot\Tests\TestCase;
use PHPUnit\Framework\MockObject\Stub;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\NullLogger;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use Stripe\Collection;
use Stripe\PaymentMethod;
use Stripe\Service\CustomerService;
use Stripe\Service\PaymentMethodService;
use Stripe\StripeClient;

class DeletePaymentMethodTest extends TestCase
{
    public function testItDeletesAPaymentMethod(): void
    {
        // arrange
        $stripePaymentMethodServiceProphecy = $this->prophesize(PaymentMethodService::class);
        $stripeCustomerServiceProphecy = $this->prophesize(CustomerService::class);
        $fakeStripeClient = $this->fakeStripeClient($stripePaymentMethodServiceProphecy, $stripeCustomerServiceProphecy);

        $stripeCustomerServiceProphecy->allPaymentMethods('stripe_customer_id_12')->willReturn(
            $this->stubCollectionOf([new PaymentMethod('stripe_payment_method_id_35')])
        );

        $sut = new DeletePaymentMethod($fakeStripeClient, new NullLogger());

        $request = (new ServerRequest())
            ->withAttribute('pspId', 'stripe_customer_id_12');

        // assert
        $stripePaymentMethodServiceProphecy->detach('stripe_payment_method_id_35')
            ->shouldBeCalledOnce();

        // act
        $sut->__invoke($request, new Response(), ['payment_method_id' => 'stripe_payment_method_id_35']);
    }

    public function testItRefusesToToDeletePaymentMethodThatDoesNotBelongToRquester(): void
    {
        $stripePaymentMethodServiceProphecy = $this->prophesize(PaymentMethodService::class);
        $stripeCustomerServiceProphecy = $this->prophesize(CustomerService::class);
        $fakeStripeClient = $this->fakeStripeClient($stripePaymentMethodServiceProphecy, $stripeCustomerServiceProphecy);

        $stripeCustomerServiceProphecy->allPaymentMethods('stripe_customer_id_12')->willReturn(
            $this->stubCollectionOf([new PaymentMethod('different_method_id')])
        );

        $sut = new DeletePaymentMethod($fakeStripeClient, new NullLogger());

        $request = (new ServerRequest())
            ->withAttribute('pspId', 'stripe_customer_id_12');

        // assert
        $stripePaymentMethodServiceProphecy->detach(Argument::any())->shouldNotBeCalled();

        // act
        $sut->__invoke($request, new Response(), ['payment_method_id' => 'stripe_payment_method_id_35']);
    }

    public function stubCollectionOf(array $paymentMethods): Stub&Collection
    {
        $stubCollection = $this->createStub(Collection::class);
        $stubCollection->method('toArray')->willReturn($paymentMethods);

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
