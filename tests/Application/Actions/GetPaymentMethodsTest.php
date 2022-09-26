<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions;

use DI\Container;
use MatchBot\Tests\TestCase;
use Stripe\Service\CustomerService;
use Stripe\StripeClient;

class GetPaymentMethodsTest extends TestCase
{
    public function testSuccess(): void
    {
        $app = $this->getAppInstance();
        /** @var Container $container */
        $container = $app->getContainer();

        $stripeCustomersProphecy = $this->prophesize(CustomerService::class);
        $stripeCustomersProphecy->allPaymentMethods('cus_aaaaaaaaaaaa11', ['type' => 'card'])
            ->shouldBeCalledOnce()
            ->willReturn([
                'data' => [
                    [
                        'id' => 'pm_123',
                        'card' => [
                            'brand' => 'visa',
                            'last4' => '4242',
                            'exp_month' => 1,
                            'exp_year' => 2022,
                        ],
                    ],
                ],
            ]);

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        $stripeClientProphecy->customers = $stripeCustomersProphecy->reveal();

        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest('GET', '/v1/people/12345678-1234-1234-1234-1234567890ab/payment_methods')
            ->withHeader('x-tbg-auth', $this->getTestIdentityTokenComplete());

        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertCount(1, $payloadArray);

        $this->assertEquals('4242', $payloadArray['data'][0]['card']['last4']);
    }
}
