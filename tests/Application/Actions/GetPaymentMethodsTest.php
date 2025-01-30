<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions;

use DI\Container;
use MatchBot\Tests\Application\Auth\IdentityTokenTest;
use MatchBot\Tests\TestCase;
use MatchBot\Tests\TestData;
use Stripe\Collection;
use Stripe\Service\CustomerService;
use Stripe\StripeClient;

class GetPaymentMethodsTest extends TestCase
{
    public function testSuccess(): void
    {
        $app = $this->getAppInstance();
        $container = $this->diContainer();

        $stripeCustomersProphecy = $this->prophesize(CustomerService::class);
        $stripeCustomersProphecy->allPaymentMethods('cus_aaaaaaaaaaaa11', ['type' => 'card'])
            ->shouldBeCalledOnce()
            ->willReturn(Collection::constructFrom(['data' => [
                [
                    'id' => 'pm_123',
                    'allow_redisplay' => 'always',
                    'card' => [
                        'brand' => 'visa',
                        'last4' => '4242',
                        'exp_month' => 1,
                        'exp_year' => 2022,
                    ],
                ],
            ],
            ]));

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        // supressing deprecation notices for now on setting properties dynamically. Risk is low doing this in test
        // code, and may get mutation tests working again.
        @$stripeClientProphecy->customers = $stripeCustomersProphecy->reveal();

        $container->set(StripeClient::class, $stripeClientProphecy->reveal());

        $request = $this->createRequest('GET', '/v1/people/' . IdentityTokenTest::PERSON_UUID . '/payment_methods')
            ->withHeader('x-tbg-auth', TestData\Identity::getTestIdentityTokenComplete());

        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertEquals(200, $response->getStatusCode());

        $payloadArray = json_decode($payload, true);
        $this->assertCount(1, $payloadArray);

        $this->assertEquals('4242', $payloadArray['data'][0]['card']['last4']);
    }
}
