<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions;

use DI\Container;
use MatchBot\Application\Security\Security;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Tests\Application\Auth\IdentityTokenTest;
use MatchBot\Tests\TestCase;
use MatchBot\Tests\TestData;
use PhpParser\Node\Arg;
use Prophecy\Argument;
use Psr\Http\Message\RequestInterface;
use Ramsey\Uuid\Uuid;
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
        $securityProphecy = $this->prophesize(Security::class);
        $securityProphecy->requireAuthenticatedDonorAccountWithPassword(Argument::any())
            ->willReturn(
                new DonorAccount(
                    PersonId::of(Uuid::NIL),
                    EmailAddress::of('email@example.com'),
                    DonorName::of('First', 'Last'),
                    StripeCustomerId::of('cus_aaaaaaaaaaaa11'),
                )
            );

        $stripeClientProphecy = $this->prophesize(StripeClient::class);
        // supressing deprecation notices for now on setting properties dynamically. Risk is low doing this in test
        // code, and may get mutation tests working again.
        @$stripeClientProphecy->customers = $stripeCustomersProphecy->reveal(); // @phpstan-ignore property.notFound

        $container->set(StripeClient::class, $stripeClientProphecy->reveal());
        $container->set(Security::class, $securityProphecy->reveal());

        $request = $this->createRequest('GET', '/v1/people/' . IdentityTokenTest::PERSON_UUID . '/payment_methods')
            ->withHeader('x-tbg-auth', TestData\Identity::getTestIdentityTokenComplete());

        $response = $app->handle($request);

        $payload = (string) $response->getBody();

        $this->assertJson($payload);
        $this->assertSame(200, $response->getStatusCode());

        /** @var array{
         *     data: list<array{card: array{last4: string}}>,
         *     regularGivingPaymentMethod: array{card: array{last4: string}}
         *     } $payloadArray
         */
        $payloadArray = json_decode($payload, true);
        $this->assertCount(1, $payloadArray['data']);

        $this->assertSame('4242', $payloadArray['data'][0]['card']['last4']);
    }
}
