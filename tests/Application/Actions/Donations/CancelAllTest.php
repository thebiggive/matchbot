<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use DI\Container;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Matching\Allocator;
use MatchBot\Client\BadRequestException;
use MatchBot\Client\Campaign;
use MatchBot\Client\Stripe;
use MatchBot\Domain\CampaignFunding;
use MatchBot\Domain\CampaignFundingRepository;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\FundRepository;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Tests\Application\Auth\IdentityTokenTest;
use MatchBot\Tests\Application\DonationTestDataTrait;
use MatchBot\Tests\TestCase;
use MatchBot\Tests\TestData;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use Slim\Exception\HttpUnauthorizedException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

class CancelAllTest extends TestCase
{
    use DonationTestDataTrait;
    use PublicJWTAuthTrait;

    private const string SF_CAMPAIGN_ID = '7015B0000017Z3xQAE';

    private const string ROUTE = '/v1/people/' . IdentityTokenTest::PERSON_UUID . '/donations';

    public function testCancelTwoSuccess(): void
    {
        $app = $this->getAppInstance();
        $container = $this->diContainer();

        /** @var list<Donation> $twoDonations */
        $twoDonations = [
            $this->getTestDonation(
                amount: '10',
                pspMethodType: PaymentMethodType::CustomerBalance,
                tipAmount: '0', // A Customer Balance Donation may not include a tip
                collected: false,
            ),
            $this->getTestDonation(
                amount: '20',
                pspMethodType: PaymentMethodType::CustomerBalance,
                tipAmount: '0', // A Customer Balance Donation may not include a tip
                collected: false,
            ),
        ];

        $donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $donationRepoProphecy->findPendingByDonorCampaignAndMethod(
            TestData\Identity::STRIPE_ID,
            Salesforce18Id::ofCampaign(self::SF_CAMPAIGN_ID),
            PaymentMethodType::CustomerBalance,
        )
            ->willReturn([
                $twoDonations[0]->getUuid(),
                $twoDonations[1]->getUuid()
                ]);

        $donationRepoProphecy->findAndLockOneByUUID(Argument::type(UuidInterface::class))
            ->will(/**
             * @param Uuid[] $args
             */
                function ($args) use ($twoDonations) {
                    foreach ($twoDonations as $donation) {
                        if ($donation->getUuid() == $args[0]->toString()) {
                            return $donation;
                        }
                    }
                    throw new \Exception("not found");
                }
            );

        $allocatorProphecy = $this->prophesize(Allocator::class);
        $allocatorProphecy->releaseMatchFunds(Argument::type(Donation::class))
            ->shouldBeCalledTimes(2);

        $entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        /**
         * @psalm-suppress MixedFunctionCall
         */
        $entityManagerProphecy->wrapInTransaction(Argument::type(\Closure::class))
            ->will(function (array $args): mixed {
                return $args[0]();
            });

        $entityManagerProphecy->persist(Argument::type(Donation::class))->shouldBeCalledTimes(2);
        $entityManagerProphecy->flush()->shouldBeCalledTimes(2);

        $stripeProphecy = $this->prophesize(Stripe::class);
        // For now, each test donation retrieved by the above helper uses the same txn ID.
        $stripeProphecy->cancelPaymentIntent($twoDonations[0]->getTransactionId())
            ->shouldBeCalledTimes(2);

        $this->setDoublesInContainer($container, $donationRepoProphecy, $entityManagerProphecy, $stripeProphecy, $allocatorProphecy);

        // act
        $request = $this->createRequest('DELETE', self::ROUTE)
            ->withQueryParams([
                'campaignId' => self::SF_CAMPAIGN_ID,
                'paymentMethodType' => PaymentMethodType::CustomerBalance->value,
            ])
            ->withHeader('x-tbg-auth', TestData\Identity::getTestIdentityTokenComplete());
        $response = $app->handle($request);

        // assert
        $this->assertSame(200, $response->getStatusCode());
        $json = (string) $response->getBody();
        $this->assertJson($json);
        /** @var array{donations: list<array{donationAmount: float|int, status: string}>} $payload */
        $payload = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('donations', $payload);
        $this->assertCount(2, $payload['donations']);

        $donationZero = $payload['donations'][0];
        $this->assertSame('Cancelled', $donationZero['status']);
        $this->assertSame(10, $donationZero['donationAmount']);
    }

    public function testNoAuth(): void
    {
        // arrange
        $app = $this->getAppInstance();
        $request = $this->createRequest('DELETE', self::ROUTE)
            ->withQueryParams([
                'campaignId' => self::SF_CAMPAIGN_ID,
                'paymentMethodType' => PaymentMethodType::CustomerBalance,
            ]);

        // assert
        $this->expectException(HttpUnauthorizedException::class);
        $this->expectExceptionMessage('Unauthorised');

        // act
        $app->handle($request);
    }

    public function testMissingRequiredQueryParam(): void
    {
        // arrange
        $app = $this->getAppInstance();
        $container = $this->diContainer();
        $request = $this->createRequest('DELETE', self::ROUTE)
            ->withQueryParams(['campaignId' => self::SF_CAMPAIGN_ID]) // missing paymentMethodType
            ->withHeader('x-tbg-auth', TestData\Identity::getTestIdentityTokenComplete());

        $this->setDoublesInContainer(
            $container,
            $this->prophesize(DonationRepository::class),
            $this->prophesize(EntityManagerInterface::class)
        );

        // assert
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Missing campaign ID or payment method type');

        // act
        $app->handle($request);
    }

    /**
     * @param Container $container
     * @param ObjectProphecy<DonationRepository> $donationRepoProphecy
     * @param ObjectProphecy<EntityManagerInterface> $entityManagerProphecy
     * @param ObjectProphecy<Stripe>|null $stripeProphecy
     * @param ObjectProphecy<Allocator>|null $allocatorProphecy
     */
    private function setDoublesInContainer(
        Container $container,
        ObjectProphecy $donationRepoProphecy,
        ObjectProphecy $entityManagerProphecy,
        ?ObjectProphecy $stripeProphecy = null,
        ?ObjectProphecy $allocatorProphecy = null,
    ): void {
        $container->set(CampaignFundingRepository::class, $this->prophesize(CampaignFundingRepository::class)->reveal());
        $container->set(DonationRepository::class, $donationRepoProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(EntityManagerInterface::class, $entityManagerProphecy->reveal());
        $container->set(CampaignRepository::class, $this->prophesize(CampaignRepository::class)->reveal());
        $container->set(DonorAccountRepository::class, $this->prophesize(DonorAccountRepository::class)->reveal());
        $container->set(FundRepository::class, $this->createStub(FundRepository::class));

        $container->set(LockFactory::class, new LockFactory(new InMemoryStore()));

        if ($stripeProphecy !== null) {
            $container->set(Stripe::class, $stripeProphecy->reveal());
        }

        if ($allocatorProphecy !== null) {
            $container->set(Allocator::class, $allocatorProphecy->reveal());
        }
    }
}
