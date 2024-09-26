<?php

namespace MatchBot\Tests\Domain;

use Doctrine\DBAL\Driver\PDO\Exception;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use MatchBot\Application\HttpModels\DonationCreate;
use MatchBot\Application\Matching\Adapter;
use MatchBot\Application\Notifier\StripeChatterInterface;
use MatchBot\Application\Persistence\RetrySafeEntityManager;
use MatchBot\Client\Stripe;
use MatchBot\Domain\CampaignRepository;
use MatchBot\Domain\DayOfMonth;
use MatchBot\Domain\DomainException\CharityAccountLacksNeededCapaiblities;
use MatchBot\Domain\DomainException\MandateNotActive;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationSequenceNumber;
use MatchBot\Domain\DonationService;
use MatchBot\Domain\DonorAccount;
use MatchBot\Domain\DonorAccountRepository;
use MatchBot\Domain\DonorName;
use MatchBot\Domain\EmailAddress;
use MatchBot\Domain\Money;
use MatchBot\Domain\PaymentMethodType;
use MatchBot\Domain\PersonId;
use MatchBot\Domain\RegularGivingMandate;
use MatchBot\Domain\Salesforce18Id;
use MatchBot\Domain\StripeCustomerId;
use MatchBot\Domain\StripePaymentMethodId;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Stripe\Exception\PermissionException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class DonationServiceTest extends TestCase
{
    private const CUSTOMER_ID = 'cus_CUSTOMERID';
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
        $donorId = PersonId::of(Uuid::NIL);

        $this->donationRepoProphecy->buildFromApiRequest($donationCreate, $donorId)->willReturn($donation);

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

        $this->sut->createDonation($donationCreate, self::CUSTOMER_ID, PersonId::of(Uuid::NIL));
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
        $donorId = PersonId::of(Uuid::NIL);

        $this->donationRepoProphecy->buildFromApiRequest($donationCreate, $donorId)->willReturn($donation);
        $this->stripeProphecy->createPaymentIntent(Argument::any())
            ->willReturn($this->prophesize(\Stripe\PaymentIntent::class)->reveal());

        $this->expectException(UniqueConstraintViolationException::class);

        $this->sut->createDonation($donationCreate, self::CUSTOMER_ID, PersonId::of(Uuid::NIL));
    }

    public function testRefusesToConfirmPreAuthedDonationForNonActiveMandate(): void
    {
        $mandate = new RegularGivingMandate(
            PersonId::of(Uuid::MAX),
            Money::fromPoundsGBP(1),
            Salesforce18Id::ofCampaign('xxxxxxxxxxxxxxxxxx'),
            Salesforce18Id::ofCharity('xxxxxxxxxxxxxxxxxx'),
            false,
            DayOfMonth::of(1),
        );

        $mandate->activate(new \DateTimeImmutable('2024-09-01'));

        $stripeCustomerId = StripeCustomerId::of('cus_123');
        $donor = new DonorAccount(
            null,
            EmailAddress::of('example@email.com'),
            DonorName::of('first', 'last'),
            $stripeCustomerId,
        );
        $donor->setBillingPostcode('SW11AA');
        $donor->setBillingCountryCode('GB');
        $donor->setRegularGivingPaymentMethod(StripePaymentMethodId::of('pm_paymentMethodID'));

        $donation = $mandate->createPreAuthorizedDonation(
            DonationSequenceNumber::of(2),
            $donor,
            TestCase::someCampaign(),
        );

        $this->donorAccountRepoProphecy->findByStripeIdOrNull($stripeCustomerId)->willReturn($donor);

        $mandate->cancel();
        $this->expectException(MandateNotActive::class);
        $this->expectExceptionMessage("Not confirming donation as mandate is 'Cancelled', not Active");

        $this->getDonationService(false)->confirmPreAuthorized($donation);
    }

    private function getDonationService(
        bool $withAlwaysCrashingEntityManager,
        LoggerInterface $logger = null,
    ): DonationService {
        $emProphecy = $this->prophesize(RetrySafeEntityManager::class);
        if ($withAlwaysCrashingEntityManager) {
            /**
             * @psalm-suppress InternalMethod
             * @psalm-suppress InternalClass Hard to simulate `final` exception otherwise
             */
            $emProphecy->persistWithoutRetries(Argument::type(Donation::class))->willThrow(
                new UniqueConstraintViolationException(new Exception('EXCEPTION_MESSAGE'), null)
            );
            $emProphecy->isOpen()->willReturn(true);
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
            clock: $this->prophesize(ClockInterface::class)->reveal(),
            rateLimiterFactory: new RateLimiterFactory(['id' => 'stub', 'policy' => 'no_limit'], new InMemoryStorage()),
            donorAccountRepository: $this->donorAccountRepoProphecy->reveal(),
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
            PersonId::of(Uuid::NIL)
        );
    }
}
