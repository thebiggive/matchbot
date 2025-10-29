<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\Donations\Confirm;
use MatchBot\Application\Environment;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Application\Matching\Allocator;
use MatchBot\Client\Stripe;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationNotifier;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\FundRepository;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingNotifier;
use MatchBot\Domain\StripeConfirmationTokenId;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Slim\Exception\HttpBadRequestException;
use Slim\Psr7\Response;
use Stripe\ConfirmationToken;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\UnknownApiErrorException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\StripeObject;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class ConfirmTest extends TestCase
{
    public const string PAYMENT_INTENT_ID = 'pi_PAYMENTINTENTID';
    public const string PAYMENT_METHOD_ID = 'pm_PAYMENTMETHODID';
    public const string CONFIRMATION_TOKEN_ID = 'ctoken_CONFIRMATIONTOKENID';

    private Confirm $sut;

    /** @var ObjectProphecy<Stripe>  */
    private ObjectProphecy $stripeProphecy;
    private bool $donationIsCancelled = false;

    /** @var ObjectProphecy<EntityManagerInterface>  */
    private ObjectProphecy $entityManagerProphecy;
    private \Ramsey\Uuid\UuidInterface $donationId;

    private const array TYPICAL_METADATA_UPDATE = [
        "metadata" => [
            "stripeFeeRechargeGross" => "2.66",
            "stripeFeeRechargeNet" => "2.22",
            "stripeFeeRechargeVat" => "0.44",
        ],
        "application_fee_amount" => 266,
    ];

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->donationId = Uuid::uuid4();
        $this->stripeProphecy = $this->prophesize(Stripe::class);
        $messageBusStub = $this->createStub(RoutableMessageBus::class);
        $messageBusStub->method('dispatch')->willReturnArgument(0);

        $this->entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $redisProphecy = $this->prophesize(\Redis::class);
        $redisProphecy->exists(Argument::any())->willReturn(1);

        $stubRateLimiter = new RateLimiterFactory(
            ['id' => 'stub', 'policy' => 'no_limit'],
            new InMemoryStorage()
        );

        $this->sut = new Confirm(
            logger: new NullLogger(),
            donationRepository: $this->getDonationRepository(),
            entityManager: $this->entityManagerProphecy->reveal(),
            bus: $messageBusStub,
            donationService: new DonationService(
                allocator: $this->createStub(Allocator::class),
                donationRepository: $this->getDonationRepository(),
                campaignRepository: $this->createStub(CampaignRepository::class),
                logger: new NullLogger(),
                entityManager: $this->createStub(EntityManagerInterface::class),
                stripe: $this->stripeProphecy->reveal(),
                matchingAdapter: $this->createStub(Adapter::class),
                chatter: $this->createStub(ChatterInterface::class),
                clock: $this->createStub(\Symfony\Component\Clock\ClockInterface::class),
                creationRateLimiterFactory: $stubRateLimiter,
                donorAccountRepository: $this->createStub(DonorAccountRepository::class),
                bus: $this->createStub(RoutableMessageBus::class),
                donationNotifier: $this->createStub(DonationNotifier::class),
                fundRepository: $this->createStub(FundRepository::class),
                redis: $redisProphecy->reveal(),
                confirmRateLimitFactory: $stubRateLimiter,
                regularGivingNotifier: $this->createStub(RegularGivingNotifier::class),
            ),
            clock: new MockClock('2025-01-01'),
            lockFactory: $this->createStub(LockFactory::class)
        );
    }

    public function testItConfirmsACardDonation(): void
    {
        // arrange
        $this->fakeStripeClient(
            cardDetails: ['brand' => 'discover', 'country' => 'KI', 'fingerprint' => 'some-fingerprint'],
            paymentMethodId: self::PAYMENT_METHOD_ID,
            updatedIntentData: [
                'status' => 'requires_action',
                'client_secret' => 'some_client_secret',
            ],
            paymentIntentId: self::PAYMENT_INTENT_ID,
            expectedMetadataUpdate: self::TYPICAL_METADATA_UPDATE,
            confirmFailsWithCardError: false,
            confirmFailsWithApiError: false,
            confirmFailsWithPaymentMethodUsedError: false,
            confirmationTokenId: self::CONFIRMATION_TOKEN_ID,
        );

        // Make sure the latest fees, based on card type, are saved to the database.
        $this->entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $this->entityManagerProphecy->flush()->shouldBeCalledOnce();
        $this->entityManagerProphecy->commit()->shouldBeCalledOnce();

        // act
        $response = $this->callConfirm(sut: $this->sut, futureUsage: PaymentIntent::SETUP_FUTURE_USAGE_ON_SESSION);

        // assert

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['paymentIntent' => ['status' => 'requires_action', 'client_secret' => 'some_client_secret']],
            \json_decode($response->getBody()->getContents(), true)
        );
    }

    public function testItChargesMinimumFeeOnGBVisaCardDonation(): void
    {
        // arrange
        $this->fakeStripeClient(
            cardDetails: ['brand' => 'visa', 'country' => 'GB', 'fingerprint' => 'some-fingerprint'],
            paymentMethodId: self::PAYMENT_METHOD_ID,
            updatedIntentData: [
                'status' => 'requires_action',
                'client_secret' => 'some_client_secret',
            ],
            paymentIntentId: self::PAYMENT_INTENT_ID,
            // £63 donation incurs a fee of 20p + (1.5% == 0.945) == £1.15 (rounded), net.
            expectedMetadataUpdate: [
                "metadata" => [
                    "stripeFeeRechargeGross" => '1.38',
                    "stripeFeeRechargeNet" => '1.15',
                    "stripeFeeRechargeVat" => '0.23',
                ],
                "application_fee_amount" => 138,
            ],
            confirmFailsWithCardError: false,
            confirmFailsWithApiError: false,
            confirmFailsWithPaymentMethodUsedError: false,
            confirmationTokenId: self::CONFIRMATION_TOKEN_ID,
        );

        // Make sure the latest fees, based on card type, are saved to the database.
        $this->entityManagerProphecy->beginTransaction()->shouldBeCalledOnce();
        $this->entityManagerProphecy->flush()->shouldBeCalledOnce();
        $this->entityManagerProphecy->commit()->shouldBeCalledOnce();

        // act
        $response = $this->callConfirm(sut: $this->sut, futureUsage: PaymentIntent::SETUP_FUTURE_USAGE_ON_SESSION);

        // assert
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            ['paymentIntent' => ['status' => 'requires_action', 'client_secret' => 'some_client_secret']],
            \json_decode($response->getBody()->getContents(), true)
        );
    }

    public function testItReturns400OnCancelledDonation(): void
    {
        // arrange
        $newCharityFee = '42.00';
        $this->successReadyFakeStripeClient(amountInWholeUnits: $newCharityFee);
        $this->donationIsCancelled = true;

        // assert
        $this->expectException(HttpBadRequestException::class);
        $this->expectExceptionMessage(
            "Donation status is 'Cancelled', must be 'Pending' or 'PreAuthorized' to confirm payment"
        );

        // act
        $this->callConfirm(sut: $this->sut, futureUsage: null);
    }

    public function testItReturns402OnDecline(): void
    {
        // arrange

        $this->fakeStripeClient(
            cardDetails: ['brand' => 'discover', 'country' => 'KI', 'fingerprint' => 'some-fingerprint'],
            paymentMethodId: self::PAYMENT_METHOD_ID,
            updatedIntentData: [
                'status' => 'requires_payment_method',
                'client_secret' => 'some_client_secret',
            ],
            paymentIntentId: self::PAYMENT_INTENT_ID,
            expectedMetadataUpdate: self::TYPICAL_METADATA_UPDATE,
            confirmFailsWithCardError: true,
            confirmFailsWithApiError: false,
            confirmFailsWithPaymentMethodUsedError: false,
            confirmationTokenId: self::CONFIRMATION_TOKEN_ID,
        );


        // act
        $response = $this->callConfirm(sut: $this->sut, futureUsage: PaymentIntent::SETUP_FUTURE_USAGE_ON_SESSION);

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

    public function testItReturns500OnApiError(): void
    {
        // arrange
        $this->fakeStripeClient(
            cardDetails: ['brand' => 'discover', 'country' => 'KI', 'fingerprint' => 'some-fingerprint'],
            paymentMethodId: self::PAYMENT_METHOD_ID,
            updatedIntentData: [
                'status' => 'requires_payment_method',
                'client_secret' => 'some_client_secret',
            ],
            paymentIntentId: self::PAYMENT_INTENT_ID,
            expectedMetadataUpdate: self::TYPICAL_METADATA_UPDATE,
            confirmFailsWithCardError: false,
            confirmFailsWithApiError: true,
            confirmFailsWithPaymentMethodUsedError: false,
            confirmationTokenId: self::CONFIRMATION_TOKEN_ID,
        );

        // act
        $response = $this->callConfirm(sut: $this->sut, futureUsage: PaymentIntent::SETUP_FUTURE_USAGE_ON_SESSION);

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

    public function testNewlyNullFutureUsageConfirmsViaNewPaymentIntent(): void
    {
        $this->fakeStripeClient(
            cardDetails: ['brand' => 'discover', 'country' => 'KI', 'fingerprint' => 'some-fingerprint'],
            paymentMethodId: self::PAYMENT_METHOD_ID,
            updatedIntentData: [
                'status' => 'requires_action',
                'client_secret' => 'some_client_secret',
            ],
            paymentIntentId: self::PAYMENT_INTENT_ID,
            expectedMetadataUpdate: [
                "metadata" => [
                    "stripeFeeRechargeGross" => "2.66",
                    "stripeFeeRechargeNet" => "2.22",
                    "stripeFeeRechargeVat" => "0.44",
                ],
                "application_fee_amount" => 266,
            ],
            confirmFailsWithCardError: true,
            confirmFailsWithApiError: false,
            confirmFailsWithPaymentMethodUsedError: false,
            updatePaymentIntentAndConfirmExpected: true,
            paymentIntentRecreateExpected: true,
            confirmationTokenId: self::CONFIRMATION_TOKEN_ID,
        );

        // Every PI starts off with future usage null until JS says otherwise via a Confirm,
        // so the most realistic way to simulate a yes followed by a no is to confirm twice,
        // first with 'on_session' and then with null.
        $firstResponse = $this->callConfirm(sut: $this->sut, futureUsage: PaymentIntent::SETUP_FUTURE_USAGE_ON_SESSION); // Decline
        $this->assertSame(402, $firstResponse->getStatusCode()); // 'Payment required'.

        $secondResponse = $this->callConfirm(sut: $this->sut, futureUsage: null); // Re-create PI and decline
        $this->assertSame(200, $secondResponse->getStatusCode()); // `$confirmationRetryWhichSucceeds` doesn't throw.
    }

    private function successReadyFakeStripeClient(string $amountInWholeUnits): void
    {
        $this->fakeStripeClient(
            cardDetails: ['brand' => 'discover', 'country' => 'KI', 'fingerprint' => 'some-fingerprint'],
            paymentMethodId: self::PAYMENT_METHOD_ID,
            updatedIntentData: [
                'status' => 'requires_action',
                'client_secret' => 'some_client_secret',
            ],
            paymentIntentId: self::PAYMENT_INTENT_ID,
            expectedMetadataUpdate: [
                'metadata' => [
                    'stripeFeeRechargeGross' => $amountInWholeUnits,
                    'stripeFeeRechargeNet' => $amountInWholeUnits,
                    'stripeFeeRechargeVat' => '0.00',
                ],
                'application_fee_amount' => 100 * (int) $amountInWholeUnits,
            ],
            confirmFailsWithCardError: false,
            confirmFailsWithApiError: false,
            confirmFailsWithPaymentMethodUsedError: false,
            updatePaymentIntentAndConfirmExpected: false,
        );
    }

    /**
     * @param array<mixed> $cardDetails
     * @param array<mixed> $updatedIntentData
     * @param array<mixed> $expectedMetadataUpdate
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
        bool $updatePaymentIntentAndConfirmExpected = true,
        bool $paymentIntentRecreateExpected = false,
        ?string $confirmationTokenId = null,
    ): void {
        $paymentMethod = new PaymentMethod(['id' => 'id-doesnt-matter-for-test']);
        $paymentMethod->type = 'card';

        /** @psalm-suppress InvalidPropertyAssignmentValue */
        $paymentMethod->card = $cardDetails; //  @phpstan-ignore assign.propertyType

        $updatedPaymentIntent = new PaymentIntent(['id' => self::PAYMENT_INTENT_ID, ...$updatedIntentData]);
        $updatedPaymentIntent->status = $updatedIntentData['status'];
        $updatedPaymentIntent->client_secret = $updatedIntentData['client_secret']; // here
        $updatedPaymentIntent->setup_future_usage = PaymentIntent::SETUP_FUTURE_USAGE_ON_SESSION; // Simulate box checked in Donate on 1st attempt

        $this->stripeProphecy->retrievePaymentIntent($paymentIntentId)
            ->willReturn($updatedPaymentIntent);

        if ($paymentIntentRecreateExpected) {
            $newPaymentIntent = new PaymentIntent(['id' => 'pi_new-id-for-test', ...$updatedIntentData]);
            // We would of course expect a new ID from real calls, but making this conditional
            // should be enough to check we're re-creating PIs only if needed.
            $this->stripeProphecy->createPaymentIntent(Argument::any())
                ->shouldBeCalledOnce()
                ->willReturn($newPaymentIntent);
        }

        if (is_string($confirmationTokenId)) {
            $confirmationToken = new ConfirmationToken();

            /** @psalm-suppress InvalidPropertyAssignmentValue */
            $confirmationToken->payment_method_preview = new StripeObject();

            /** @psalm-suppress InvalidPropertyAssignmentValue */
            $confirmationToken->payment_method_preview['type'] = 'card';

            /** @psalm-suppress InvalidPropertyAssignmentValue */
            $confirmationToken->payment_method_preview['card'] = $cardDetails;

            /** @psalm-suppress InvalidPropertyAssignmentValue */
            $confirmationToken->payment_method_preview['pay_by_bank'] = null;

            $confirmationToken->setup_future_usage = ConfirmationToken::SETUP_FUTURE_USAGE_ON_SESSION; // Simulate box checked in Donate on 1st attempt

            $this->stripeProphecy->retrieveConfirmationToken(StripeConfirmationTokenId::of($confirmationTokenId))
                ->willReturn($confirmationToken);
        }

        if (!$updatePaymentIntentAndConfirmExpected) {
            return;
        }

        $this->stripeProphecy->updatePaymentIntent(
            $paymentIntentId,
            $expectedMetadataUpdate
        )->shouldBeCalled();

        $returnUrl = Environment::current()->publicDonateURLPrefix() . 'thanks/' . $this->donationId->toString();

        if (is_string($confirmationTokenId)) {
            $confirmation = $this->stripeProphecy->confirmPaymentIntent(
                $paymentIntentId,
                [
                    'confirmation_token' => $confirmationTokenId,
                    'return_url' => $returnUrl,
                ]
            )->willReturn($updatedPaymentIntent);

            $confirmationRetryWhichSucceeds = $this->stripeProphecy->confirmPaymentIntent(
                $paymentIntentRecreateExpected ? 'pi_new-id-for-test' : self::PAYMENT_INTENT_ID,
                [
                    "confirmation_token" => $confirmationTokenId,
                    'return_url' => $returnUrl,
                ]
            )->willReturn($updatedPaymentIntent);
            $confirmationRetryWhichSucceeds->shouldBeCalled();
        } else {
            $confirmation = $this->stripeProphecy->confirmPaymentIntent(
                $paymentIntentId,
                [
                    'payment_method' => $paymentMethodId,
                    'return_url' => $returnUrl,
                ]
            )->willReturn($updatedPaymentIntent);
        }

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
    }

    /**
     * @return DonationRepository Really an ObjectProphecy<DonationRepository>, but psalm
     *                            complains about that.
     */
    private function getDonationRepository(): DonationRepository
    {
        $donationRepositoryProphecy = $this->prophesize(DonationRepository::class);

        $testCase = $this;
        $donationRepositoryProphecy->findAndLockOneByUUID($this->donationId)
            ->will(function () use ($testCase) {
                $donation = Donation::fromApiModel(
                    new DonationCreate(
                        currencyCode: 'GBP',
                        donationAmount: '63.0',
                        projectId: 'doesnt0matter12345',
                        psp: 'stripe',
                        countryCode: 'GB',
                    ),
                    $testCase->getMinimalCampaign(),
                    PersonId::nil(),
                );
                $donation->setUuid($testCase->donationId);

                $donation->update(
                    paymentMethodType: PaymentMethodType::Card,
                    giftAid: false,
                    donorBillingPostcode: 'SW1 1AA',
                    donorName: DonorName::of('Charlie', 'The Charitable'),
                    donorEmailAddress: EmailAddress::of('user@example.com'),
                );

                $donation->setTransactionId(self::PAYMENT_INTENT_ID);
                if ($testCase->donationIsCancelled) {
                    $donation->cancel();
                }

                return $donation;
            });

        return $donationRepositoryProphecy->reveal();
    }

    private function callConfirm(Confirm $sut, ?string $futureUsage): ResponseInterface
    {
        return $sut(
            self::createRequest(
                method: 'POST',
                path: 'doesnt-matter-for-test',
                bodyString: \json_encode([
                    'stripeConfirmationTokenId' => self::CONFIRMATION_TOKEN_ID,
                    'stripeConfirmationTokenFutureUsage' => $futureUsage,
                ], \JSON_THROW_ON_ERROR)
            ),
            new Response(),
            ['donationId' => $this->donationId->toString()]
        );
    }
}
