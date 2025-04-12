<?php

namespace MatchBot\Tests\Domain;

use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MatchBot\Application\Environment;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Application\Notifier\StripeChatterInterface;
use Doctrine\ORM\EntityManagerInterface;
use MatchBot\Client\Stripe;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\Country;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\DomainException\CharityAccountLacksNeededCapaiblities;
use MatchBot\Domain\DomainException\MandateNotActive;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationNotifier;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationSequenceNumber;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\MandateCancellationType;
use MatchBot\Domain\Money;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\StripeConfirmationTokenId;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Domain\StripePaymentMethodId;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Stripe\ConfirmationToken;
use Stripe\Exception\PermissionException;
use Stripe\PaymentIntent;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\RoutableMessageBus;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class DonationServiceTest extends TestCase
{
    private const string CUSTOMER_ID = 'cus_CUSTOMERID';
    private DonationService $sut;

    /** @var ObjectProphecy<Stripe> */
    private ObjectProphecy $stripeProphecy;

    /** @var ObjectProphecy<DonationRepository> */
    private ObjectProphecy $donationRepoProphecy;

    /** @var ObjectProphecy<StripeChatterInterface> */
    private ObjectProphecy $chatterProphecy;

    /** @var ObjectProphecy<DonorAccountRepository> */
    private ObjectProphecy $donorAccountRepoProphecy;

    public function setUp(): void
    {
        $this->donorAccountRepoProphecy = $this->prophesize(DonorAccountRepository::class);
        $this->donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $this->stripeProphecy = $this->prophesize(Stripe::class);
        $this->chatterProphecy = $this->prophesize(StripeChatterInterface::class);
    }

    public function testIdentifiesCharityLackingCapabilities(): void
    {
        $this->sut = $this->getDonationService(withAlwaysCrashingEntityManager: false);

        $donationCreate = $this->getDonationCreate();
        $donation = $this->getDonation();

        /** @psalm-suppress DeprecatedMethod */
        $this->donationRepoProphecy->buildFromApiRequest(
            $donationCreate,
            Argument::type(PersonId::class),
            Argument::type(DonationService::class)
        )->willReturn($donation);

        $this->chatterProphecy->send(
            new ChatMessage(
                "[test] Stripe Payment Intent create error on {$donation->getUuid()}" .
                ', unknown [Stripe\Exception\PermissionException]: ' .
                'Your destination account needs to have at least one of the following capabilities enabled: ' .
                'transfers, crypto_transfers, legacy_payments. Charity: ' .
                'Charity Name [STRIPE-ACCOUNT-ID].'
            )
        )->shouldBeCalledOnce();

        $this->stripeProphecy->createPaymentIntent(Argument::any())
            ->willThrow(new PermissionException(
                'Your destination account needs to have at least one of the following capabilities ' .
                'enabled: transfers, crypto_transfers, legacy_payments'
            ));

        $this->expectException(CharityAccountLacksNeededCapaiblities::class);

        $this->sut->createDonation($donationCreate, self::CUSTOMER_ID, PersonId::nil());
    }

    public function testInitialPersistRunsOutOfRetries(): void
    {
        $logger = $this->prophesize(LoggerInterface::class);
        $logger->info(
            'Donation Create persist before stripe work error: ' .
            'An exception occurred in the driver: EXCEPTION_MESSAGE. Retrying 1 of 3.'
        )->shouldBeCalledOnce();
        $logger->info(Argument::type('string'))->shouldBeCalled();
        $logger->error(
            'Donation Create persist before stripe work error: ' .
            'An exception occurred in the driver: EXCEPTION_MESSAGE. Giving up after 3 retries.'
        )
            ->shouldBeCalledOnce();

        $this->sut = $this->getDonationService(withAlwaysCrashingEntityManager: true, logger: $logger->reveal());

        $donationCreate = $this->getDonationCreate();
        $donation = $this->getDonation();

        $this->donationRepoProphecy->buildFromApiRequest($donationCreate, Argument::type(PersonId::class), Argument::type(DonationService::class))->willReturn($donation);
        $this->stripeProphecy->createPaymentIntent(Argument::any())
            ->willReturn($this->prophesize(\Stripe\PaymentIntent::class)->reveal());

        $this->expectException(UniqueConstraintViolationException::class);

        $this->sut->createDonation($donationCreate, self::CUSTOMER_ID, PersonId::nil());
    }

    public function testRefusesToConfirmPreAuthedDonationForNonActiveMandate(): void
    {
        $mandate = self::someMandate();

        $mandate->activate(new \DateTimeImmutable('2024-09-01'));

        $stripeCustomerId = StripeCustomerId::of('cus_123');
        $donor = new DonorAccount(
            self::randomPersonId(),
            EmailAddress::of('example@email.com'),
            DonorName::of('first', 'last'),
            $stripeCustomerId,
        );
        $donor->setBillingPostcode('SW11AA');
        $donor->setBillingCountry(Country::GB());
        $donor->setRegularGivingPaymentMethod(StripePaymentMethodId::of('pm_paymentMethodID'));

        $donation = $mandate->createPreAuthorizedDonation(
            DonationSequenceNumber::of(2),
            $donor,
            TestCase::someCampaign(),
        );

        $this->donorAccountRepoProphecy->findByStripeIdOrNull($stripeCustomerId)->willReturn($donor);

        $mandate->cancel(
            reason: '',
            at: new \DateTimeImmutable(),
            type: MandateCancellationType::DonorRequestedCancellation
        );

        $this->expectException(MandateNotActive::class);
        $this->expectExceptionMessage("Not confirming donation as mandate is 'Cancelled', not Active");

        $this->getDonationService()->confirmPreAuthorized($donation);
    }

    private function getDonationService(
        bool $withAlwaysCrashingEntityManager = false,
        LoggerInterface $logger = null,
    ): DonationService {
        $emProphecy = $this->prophesize(EntityManagerInterface::class);
        if ($withAlwaysCrashingEntityManager) {
            /**
             * @psalm-suppress InternalMethod
             * @psalm-suppress InternalClass Hard to simulate `final` exception otherwise
             */
            $emProphecy->persist(Argument::type(Donation::class))->willThrow(
                new UniqueConstraintViolationException(new Exception('EXCEPTION_MESSAGE'), null)
            );
        } else {
            $emProphecy->persist(Argument::type(Donation::class))->willReturn(null);
            $emProphecy->flush()->willReturn(null);
        }

        $logger = $logger ?? new NullLogger();


        return new DonationService(
            donationRepository: $this->donationRepoProphecy->reveal(),
            campaignRepository: $this->prophesize(CampaignRepository::class)->reveal(),
            logger: $logger,
            entityManager: $emProphecy->reveal(),
            stripe: $this->stripeProphecy->reveal(),
            matchingAdapter: $this->prophesize(Adapter::class)->reveal(),
            chatter: $this->chatterProphecy->reveal(),
            clock: new MockClock(new \DateTimeImmutable('2025-01-01')),
            rateLimiterFactory: new RateLimiterFactory(['id' => 'stub', 'policy' => 'no_limit'], new InMemoryStorage()),
            donorAccountRepository: $this->donorAccountRepoProphecy->reveal(),
            bus: $this->createStub(RoutableMessageBus::class),
            donationNotifier: $this->createStub(DonationNotifier::class),
        );
    }

    private function getDonationCreate(): DonationCreate
    {
        return new DonationCreate(
            currencyCode: 'GBP',
            donationAmount: '1',
            projectId: 'projectIDxxxxxxxxx',
            psp: 'stripe',
            pspCustomerId: self::CUSTOMER_ID
        );
    }

    private function getDonation(): Donation
    {
        return Donation::fromApiModel(
            $this->getDonationCreate(),
            TestCase::someCampaign(stripeAccountId: 'STRIPE-ACCOUNT-ID'),
            PersonId::nil()
        );
    }

    /**
     * Not attempting to cover all possible variations here, just one example of confirming via the
     * \MatchBot\Domain\DonationService::confirmOnSessionDonation . Details of the fee calcuations are
     * tested directly against the Calculator class.
     */
    public function testItConfirmsOnSessionUKVisaDonationChargingAppropriateFee(): void
    {
        $confirmationTokenId = StripeConfirmationTokenId::of('ctoken_xyz');
        $paymentIntentId = 'payment_intent_id';

        $donation = TestCase::someDonation(amount: '15.00');
        $donation->setTransactionId($paymentIntentId);

        $this->stripeProphecy->retrieveConfirmationToken($confirmationTokenId)
            ->will(function () {
                $confirmationToken = new ConfirmationToken();
                /** @psalm-suppress InvalidPropertyAssignmentValue */
                $confirmationToken->payment_method_preview = [
                    'card' => [
                        'brand' => 'visa',
                        'country' => 'gb',
                    ],
                ];
                return $confirmationToken;
            });

        // Gross fee is £0.52 because in the case of a UK visa card the fee is
        // 1.5% * the donation amount, plus 20p, plus 20% vat - and we round the net amount to pence
        // before adding VAT, then round it again after:
        $this->assertSame(
            52.0,
            round(round(15_00 * 1.5 / 100 + 20) * 1.2)
        );

        $this->stripeProphecy->updatePaymentIntent($paymentIntentId, [
            'metadata' => [
                'stripeFeeRechargeGross' => '0.52',
                'stripeFeeRechargeNet' => '0.43',
                'stripeFeeRechargeVat' => '0.09',
            ],
            'application_fee_amount' => '52',
        ])->shouldBeCalledOnce();

        $paymentIntent = new PaymentIntent($paymentIntentId);
        $paymentIntent->status = PaymentIntent::STATUS_SUCCEEDED;

        $this->stripeProphecy->confirmPaymentIntent($paymentIntentId, [
            'confirmation_token' => $confirmationTokenId->stripeConfirmationTokenId,
            'capture_method' => 'automatic',
        ])
            ->willReturn($paymentIntent)
            ->shouldBeCalledOnce();

        // act
        $this->getDonationService()->confirmOnSessionDonation($donation, $confirmationTokenId);

        $this->assertEquals('0.52', $donation->getCharityFeeGross());
    }
}
