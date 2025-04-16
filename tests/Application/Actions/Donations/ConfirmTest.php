<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use Assert\AssertionFailedException;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Application\Actions\Donations\Confirm;
use MatchBot\Application\Environment;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching\Adapter;
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
use MatchBot\Domain\PersonId;
use MatchBot\Domain\StripeConfirmationTokenId;
use MatchBot\Tests\TestCase;
use PhpParser\Node\Arg;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
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
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class ConfirmTest extends TestCase
{
    public const string PAYMENT_METHOD_ID = 'pm_PAYMENTMETHODID';
    public const string CONFIRMATION_TOKEN_ID = 'ctoken_CONFIRMATIONTOKENID';

    private Confirm $sut;

    /** @var ObjectProphecy<Stripe>  */
    private ObjectProphecy $stripeProphecy;
    private bool $donationIsCancelled = false;

    /** @var ObjectProphecy<EntityManagerInterface>  */
    private ObjectProphecy $entityManagerProphecy;
    private \Ramsey\Uuid\UuidInterface $donationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->donationId = Uuid::uuid4();
        $this->stripeProphecy = $this->prophesize(Stripe::class);
        $messageBusStub = $this->createStub(RoutableMessageBus::class);
        $messageBusStub->method('dispatch')->willReturnArgument(0);

        $this->entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $this->sut = new Confirm(
            logger: new NullLogger(),
            donationRepository: $this->getDonationRepository(),
            entityManager: $this->entityManagerProphecy->reveal(),
            bus: $messageBusStub,
            clock: new MockClock('2025-01-01'),
            donationService: new DonationService(
                donationRepository: $this->getDonationRepository(),
                campaignRepository: $this->createStub(CampaignRepository::class),
                logger: new NullLogger(),
                entityManager: $this->createStub(EntityManagerInterface::class),
                stripe: $this->stripeProphecy->reveal(),
                matchingAdapter: $this->createStub(Adapter::class),
                chatter: $this->createStub(ChatterInterface::class),
                clock: $this->createStub(\Symfony\Component\Clock\ClockInterface::class),
                rateLimiterFactory: new RateLimiterFactory(
                    ['id' => 'stub', 'policy' => 'no_limit'],
                    new InMemoryStorage()
                ),
                donorAccountRepository: $this->createStub(DonorAccountRepository::class),
                bus: $this->createStub(RoutableMessageBus::class),
                donationNotifier: $this->createStub(DonationNotifier::class),
                fundRepository: $this->createStub(FundRepository::class),
            )
        );
    }

    public function testItConfirmsACardDonation(): void
    {
        // arrange
        $this->fakeStripeClient(
            cardDetails: ['brand' => 'discover', 'country' => 'KI'],
            paymentMethodId: self::PAYMENT_METHOD_ID,
            updatedIntentData: [
                'status' => 'requires_action',
                'client_secret' => 'some_client_secret',
            ],
            paymentIntentId: 'PAYMENT_INTENT_ID',
            expectedMetadataUpdate: [
                "metadata" => [
                    "stripeFeeRechargeGross" => '2.66',
                    "stripeFeeRechargeNet" => "2.22",
                    "stripeFeeRechargeVat" => "0.44",
                ],
                "application_fee_amount" => 266,
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
        $response = $this->callConfirm($this->sut);

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
            cardDetails: ['brand' => 'visa', 'country' => 'GB'],
            paymentMethodId: self::PAYMENT_METHOD_ID,
            updatedIntentData: [
                'status' => 'requires_action',
                'client_secret' => 'some_client_secret',
            ],
            paymentIntentId: 'PAYMENT_INTENT_ID',
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
        $response = $this->callConfirm($this->sut);

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
        $this->successReadyFakeStripeClient(
            amountInWholeUnits: $newCharityFee,
            confirmCallExpected: false,
        );
        $this->donationIsCancelled = true;

        // assert
        $this->expectException(HttpBadRequestException::class);
        $this->expectExceptionMessage(
            "Donation status is 'Cancelled', must be 'Pending' or 'PreAuthorized' to confirm payment"
        );

        // act
        $this->callConfirm($this->sut);
    }

    public function testItReturns402OnDecline(): void
    {
        // arrange

        $this->fakeStripeClient(
            cardDetails: ['brand' => 'discover', 'country' => 'KI'],
            paymentMethodId: self::PAYMENT_METHOD_ID,
            updatedIntentData: [
                'status' => 'requires_payment_method',
                'client_secret' => 'some_client_secret',
            ],
            paymentIntentId: 'PAYMENT_INTENT_ID',
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
            confirmationTokenId: self::CONFIRMATION_TOKEN_ID,
        );


        // act
        $response = $this->callConfirm($this->sut);

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
            cardDetails: ['brand' => 'discover', 'country' => 'KI'],
            paymentMethodId: self::PAYMENT_METHOD_ID,
            updatedIntentData: [
                'status' => 'requires_payment_method',
                'client_secret' => 'some_client_secret',
            ],
            paymentIntentId: 'PAYMENT_INTENT_ID',
            expectedMetadataUpdate: [
                "metadata" => [
                    "stripeFeeRechargeGross" => "2.66",
                    "stripeFeeRechargeNet" => "2.22",
                    "stripeFeeRechargeVat" => "0.44",
                ],
                "application_fee_amount" => 266,
            ],
            confirmFailsWithCardError: false,
            confirmFailsWithApiError: true,
            confirmFailsWithPaymentMethodUsedError: false,
            confirmationTokenId: self::CONFIRMATION_TOKEN_ID,
        );

        // act
        $response = $this->callConfirm($this->sut);

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

    private function successReadyFakeStripeClient(
        string $amountInWholeUnits,
        bool $confirmCallExpected
    ): void {
        $this->fakeStripeClient(
            cardDetails: ['brand' => 'discover', 'country' => 'KI'],
            paymentMethodId: self::PAYMENT_METHOD_ID,
            updatedIntentData: [
                'status' => 'requires_action',
                'client_secret' => 'some_client_secret',
            ],
            paymentIntentId: 'PAYMENT_INTENT_ID',
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
            updatePaymentIntentAndConfirmExpected: $confirmCallExpected,
        );
    }

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
        ?string $confirmationTokenId = null,
    ): void {
        $paymentMethod = new PaymentMethod(['id' => 'id-doesnt-matter-for-test']);
        $paymentMethod->type = 'card';

        /** @psalm-suppress InvalidPropertyAssignmentValue */
        $paymentMethod->card = $cardDetails;

        $updatedPaymentIntent = new PaymentIntent(['id' => 'id-doesnt-matter-for-test', ...$updatedIntentData]);
        $updatedPaymentIntent->status = $updatedIntentData['status'];
        $updatedPaymentIntent->client_secret = $updatedIntentData['client_secret']; // here

        $this->stripeProphecy->retrievePaymentIntent($paymentIntentId)
            ->willReturn($updatedPaymentIntent);

        if (is_string($confirmationTokenId)) {
            $confirmationToken = new ConfirmationToken();
            $confirmationToken->payment_method_preview = new StripeObject();
            $confirmationToken->payment_method_preview['type'] = 'card';
            $confirmationToken->payment_method_preview['card'] = $cardDetails;

            $this->stripeProphecy->retrieveConfirmationToken(StripeConfirmationTokenId::of($confirmationTokenId))
                ->willReturn($confirmationToken);
        }

        if (!$updatePaymentIntentAndConfirmExpected) {
            return; // $this->stripeProphecy;
        }

        $this->stripeProphecy->updatePaymentIntent(
            $paymentIntentId,
            $expectedMetadataUpdate
        )->shouldBeCalled();

        if (is_string($confirmationTokenId)) {
            $confirmation = $this->stripeProphecy->confirmPaymentIntent(
                $paymentIntentId,
                [
                    "confirmation_token" => $confirmationTokenId,
                    'capture_method' => 'automatic'

                ]
            )->willReturn($updatedPaymentIntent);
        } else {
            $confirmation = $this->stripeProphecy->confirmPaymentIntent(
                $paymentIntentId,
                [
                    "payment_method" => $paymentMethodId,
                    'capture_method' => 'automatic'
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
                    giftAid: false,
                    donorBillingPostcode: 'SW1 1AA',
                    donorName: DonorName::of('Charlie', 'The Charitable'),
                    donorEmailAddress: EmailAddress::of('user@example.com'),
                );

                $donation->setTransactionId('PAYMENT_INTENT_ID');
                if ($testCase->donationIsCancelled) {
                    $donation->cancel();
                }

                return $donation;
            });

        return $donationRepositoryProphecy->reveal();
    }

    private function callConfirm(Confirm $sut): ResponseInterface
    {
        return $sut(
            self::createRequest(
                method: 'POST',
                path: 'doesnt-matter-for-test',
                bodyString: \json_encode([
                    'stripeConfirmationTokenId' => self::CONFIRMATION_TOKEN_ID
                ], \JSON_THROW_ON_ERROR)
            ),
            new Response(),
            ['donationId' => $this->donationId->toString()]
        );
    }
}
