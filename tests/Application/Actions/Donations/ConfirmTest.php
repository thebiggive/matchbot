<?php

declare(strict_types=1);

namespace MatchBot\Tests\Application\Actions\Donations;

use Doctrine\ORM\EntityManagerInterface;
use Los\RateLimit\RateLimitMiddlewareFactory;
use MatchBot\Application\Actions\Donations\Confirm;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
use MatchBot\Client\Stripe;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Slim\Exception\HttpBadRequestException;
use Slim\Psr7\Response;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\UnknownApiErrorException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Notifier\ChatterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class ConfirmTest extends TestCase
{
    private Confirm $sut;

    /** @var ObjectProphecy<Stripe>  */
    private ObjectProphecy $stripeProphecy;
    private bool $donationIsCancelled = false;

    /** @var ObjectProphecy<EntityManagerInterface>  */
    private ObjectProphecy $entityManagerProphecy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stripeProphecy = $this->prophesize(Stripe::class);
        $messageBusStub = $this->createStub(RoutableMessageBus::class);
        $messageBusStub->method('dispatch')->willReturnArgument(0);

        $this->entityManagerProphecy = $this->prophesize(EntityManagerInterface::class);

        $this->sut = new Confirm(
            new NullLogger(),
            $this->getDonationRepository(),
            $this->stripeProphecy->reveal(),
            $this->entityManagerProphecy->reveal(),
            $messageBusStub,
            new DonationService(
                $this->getDonationRepository(),
                $this->createStub(CampaignRepository::class),
                new NullLogger(),
                $this->createStub(RetrySafeEntityManager::class),
                $this->stripeProphecy->reveal(),
                $this->createStub(Adapter::class),
                $this->createStub(ChatterInterface::class),
                $this->createStub(\Symfony\Component\Clock\ClockInterface::class),
                new RateLimiterFactory(['id' => 'stub', 'policy' => 'no_limit'], new InMemoryStorage()),
            )
        );
    }

    public function testItConfirmsACardDonation(): void
    {
        // arrange
        $this->fakeStripeClient(
            cardDetails: ['brand' => 'discover', 'country' => 'some-country'],
            paymentMethodId: 'PAYMENT_METHOD_ID',
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
            cardDetails: ['brand' => 'discover', 'country' => 'some-country'],
            paymentMethodId: 'PAYMENT_METHOD_ID',
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

    /**
     * We've seen card test bots, and no humans, try to do this as of Oct '23. For now we want to log it
     * as a warning, so we can see frequency on a dashboard but don't get alarms.
     */
    public function testItReturns402OnStalePaymentMethodReuse(): void
    {
        // arrange

        // in reality the fee would be calculated according to details of the card etc. The Calculator class is
        //tested separately. This is just a dummy value.

        $this->fakeStripeClient(
            cardDetails: ['brand' => 'discover', 'country' => 'some-country'],
            paymentMethodId: 'PAYMENT_METHOD_ID',
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
            confirmFailsWithApiError: false,
            confirmFailsWithPaymentMethodUsedError: true,
        );

        // act
        $response = $this->callConfirm($this->sut);

        // assert

        $this->assertSame(402, $response->getStatusCode()); // 'Payment required'.
        $this->assertSame(
            ['error' => [
                'message' => 'Payment method cannot be used again',
                'code' => 'invalid_request_error',
            ]],
            \json_decode($response->getBody()->getContents(), true)
        );
    }

    public function testItReturns500OnApiError(): void
    {
        // arrange
        $this->fakeStripeClient(
            cardDetails: ['brand' => 'discover', 'country' => 'some-country'],
            paymentMethodId: 'PAYMENT_METHOD_ID',
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
            cardDetails: ['brand' => 'discover', 'country' => 'some-country'],
            paymentMethodId: 'PAYMENT_METHOD_ID',
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
    ): void {
        $paymentMethod = new PaymentMethod(['id' => 'id-doesnt-matter-for-test']);
        $paymentMethod->type = 'card';

        /** @psalm-suppress InvalidPropertyAssignmentValue */
        $paymentMethod->card = $cardDetails;
        $this->stripeProphecy->updatePaymentMethodBillingDetail($paymentMethodId, Argument::type(Donation::class))
            ->will(fn() => null);
        $this->stripeProphecy->retrievePaymentMethod($paymentMethodId)->willReturn($paymentMethod);

        $updatedPaymentIntent = new PaymentIntent(['id' => 'id-doesnt-matter-for-test', ...$updatedIntentData]);
        $updatedPaymentIntent->status = $updatedIntentData['status'];
        $updatedPaymentIntent->client_secret = $updatedIntentData['client_secret']; // here

        $this->stripeProphecy->retrievePaymentIntent($paymentIntentId)
            ->willReturn($updatedPaymentIntent);

        if (!$updatePaymentIntentAndConfirmExpected) {
            return; // $this->stripeProphecy;
        }

        $this->stripeProphecy->updatePaymentIntent(
            $paymentIntentId,
            $expectedMetadataUpdate
        )->shouldBeCalled();

        $confirmation = $this->stripeProphecy->confirmPaymentIntent(
            $paymentIntentId,
            ["payment_method" => $paymentMethodId]
        )->willReturn($updatedPaymentIntent);

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
        $donationRepositoryProphecy->findAndLockOneBy(['uuid' => 'DONATION_ID'])->will(function () use ($testCase) {
            $donation = Donation::fromApiModel(
                new DonationCreate(
                    currencyCode: 'GBP',
                    donationAmount: '63.0',
                    projectId: 'doesnt0matter12345',
                    psp: 'stripe',
                    countryCode: 'GB',
                ),
                $testCase->getMinimalCampaign(),
            );

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
                    'stripePaymentMethodId' => 'PAYMENT_METHOD_ID',
                ])
            ),
            new Response(),
            ['donationId' => 'DONATION_ID']
        );
    }
}
