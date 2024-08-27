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
use MatchBot\Domain\DomainException\CharityAccountLacksNeededCapaiblities;
use MatchBot\Domain\Donation;
use MatchBot\Domain\DonationRepository;
use MatchBot\Domain\DonationService;
use MatchBot\Tests\TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stripe\Customer;
use Stripe\Exception\PermissionException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

class DonationServiceTest extends TestCase
{
    private const CUSTOMER_ID = 'CUSTOMER_ID';
    private DonationService $sut;

    /** @var \Prophecy\Prophecy\ObjectProphecy<Stripe> */
    private \Prophecy\Prophecy\ObjectProphecy $stripeProphecy;

    /** @var \Prophecy\Prophecy\ObjectProphecy<DonationRepository> */
    private \Prophecy\Prophecy\ObjectProphecy $donationRepoProphecy;

    /** @var ObjectProphecy<StripeChatterInterface> */
    private ObjectProphecy $chatterProphecy;

    public function setUp(): void
    {
        $this->donationRepoProphecy = $this->prophesize(DonationRepository::class);
        $this->stripeProphecy = $this->prophesize(Stripe::class);
        $this->chatterProphecy = $this->prophesize(StripeChatterInterface::class);
    }

    public function testIdentifiesCharityLackingCapabilities(): void
    {
        $this->sut = $this->getDonationService();

        $donationCreate = $this->getDonationCreate();
        $donation = $this->getDonation();

        $this->donationRepoProphecy->buildFromApiRequest($donationCreate)->willReturn($donation);

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

        $this->sut->createDonation($donationCreate, self::CUSTOMER_ID);
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

        $this->donationRepoProphecy->buildFromApiRequest($donationCreate)->willReturn($donation);
        $this->stripeProphecy->createPaymentIntent(Argument::any())
            ->willReturn($this->prophesize(\Stripe\PaymentIntent::class)->reveal());

        $this->expectException(UniqueConstraintViolationException::class);

        $this->sut->createDonation($donationCreate, self::CUSTOMER_ID);
    }

    public function testConfirmsDonationUsingDefaultCard(): void
    {
        $sut = $this->getDonationService();

        $donation = TestCase::someDonation();
        $donation->setTransactionId('stripe-pi-id');
        $donation->setPspCustomerId('stripe-customer-id');

        $stripeCustomer = new Customer();
        $stripeCustomer->default_source = 'payment-method-id';
        $this->stripeProphecy->retrieveCustomer('stripe-customer-id')->willReturn($stripeCustomer);

        $paymentMethod = new PaymentMethod();
        $paymentMethod->type = 'card';

        /** @psalm-suppress PropertyTypeCoercion */
        $paymentMethod->card = (object)['brand' => 'visa', 'country' => 'GB'];
        $this->stripeProphecy->retrievePaymentMethod('payment-method-id')->willReturn($paymentMethod);


        $this->stripeProphecy->updatePaymentIntent(
            paymentIntentId: "stripe-pi-id",
            updateData: [
                "metadata" => [
                    "stripeFeeRechargeGross" => "0.26",
                    "stripeFeeRechargeNet" => "0.22",
                    "stripeFeeRechargeVat" => "0.04",
                ],
                "application_fee_amount" => 26
            ]
        );

        $this->stripeProphecy->confirmPaymentIntent(
            'stripe-pi-id',
            Argument::type('array')
        )
            ->shouldBeCalledOnce()
            ->willReturn(new PaymentIntent())
        ;

        $sut->confirmUsingDefaultPaymentMethod($donation);
    }

    private function getDonationService(
        bool $withAlwaysCrashingEntityManager = false,
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
            rateLimiterFactory: new RateLimiterFactory(['id' => 'stub', 'policy' => 'no_limit'], new InMemoryStorage())
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
            TestCase::someCampaign(stripeAccountId: 'STRIPE-ACCOUNT-ID')
        );
    }
}
