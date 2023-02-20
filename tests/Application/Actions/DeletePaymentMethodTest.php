<?php

namespace MatchBot\Tests\Application;

use Laminas\Diactoros\ServerRequest;
use MatchBot\Application\Actions\DeletePaymentMethod;
use MatchBot\Tests\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use Stripe\Service\CustomerService;
use Stripe\Service\PaymentMethodService;
use Stripe\StripeClient;

class DeletePaymentMethodTest extends TestCase
{
    public function testItDeletesAPaymentMethod(): void
    {
        // arrange
        $stripePaymentMethodServiceProphecy = $this->prophesize(PaymentMethodService::class);
        $fakeStripeClient = $this->createStub(StripeClient::class);
        /** @psalm-suppress UndefinedPropertyAssignment  Not sure why Psalm isn't reading the @property
         * annotation on the StripeClient class */
        $fakeStripeClient->paymentMethods = $stripePaymentMethodServiceProphecy->reveal();

        $sut = new DeletePaymentMethod($fakeStripeClient, new NullLogger());

        $request = (new ServerRequest())
            ->withAttribute('pspId', 'stripe_customer_id_12');

        // assert
        $stripePaymentMethodServiceProphecy->detach('stripe_payment_method_id_35')
            ->shouldBeCalledOnce();

        // act
        $sut->__invoke($request, new Response(), ['payment_method_id' => 'stripe_payment_method_id_35']);
    }
}